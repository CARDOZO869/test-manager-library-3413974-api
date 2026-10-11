<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
final class ConcurrentLendingTest extends TestCase {
    public function test_two_processes_compete_for_last_copy(): void {
        if (DB::connection()->getDatabaseName() !== 'manager_library_verification') $this->markTestSkipped('Run only on isolated verification database');
        $book=(string)Str::uuid();$user=(string)Str::uuid();$members=[(string)Str::uuid(),(string)Str::uuid()];
        DB::table('library.user')->insert(['id'=>$user,'username'=>'race_'.strtolower(Str::random(12)),'password_hash'=>password_hash(Str::random(24),PASSWORD_BCRYPT),'role'=>'librarian']);
        foreach($members as $member) DB::table('library.member')->insert(['id'=>$member,'full_name'=>'Prueba concurrente','document_number'=>'R-'.Str::random(20)]);
        DB::table('library.book')->insert(['id'=>$book,'title'=>'Concurrencia '.Str::random(12),'author'=>'Prueba','isbn'=>(string)random_int(1000000000000,9999999999999),'category_id'=>'11111111-1111-4111-8111-111111111111','total_copies'=>1,'available_copies'=>1]);
        $barrier=sys_get_temp_dir().'/library-race-'.Str::uuid();
        $processes=[];
        foreach($members as $index=>$member) {
            $process=new Process([PHP_BINARY,base_path('tests/Support/concurrent-loan-worker.php'),$member,$user,$book,$barrier,(string)$index],base_path(),['DB_DATABASE'=>'manager_library_verification','DB_CONNECTION'=>'pgsql']);
            $process->setTimeout(30);$process->start();$processes[]=$process;
        }
        $deadline=microtime(true)+20;
        while(!file_exists($barrier.'.0.ready') || !file_exists($barrier.'.1.ready')) {
            if(microtime(true)>$deadline) { foreach($processes as $process)$process->stop(); self::fail('Workers did not reach barrier'); }
            usleep(10000);
        }
        touch($barrier.'.go');$results=[];
        foreach($processes as $process){$process->wait();self::assertTrue($process->isSuccessful(),$process->getErrorOutput());$results[]=trim($process->getOutput());}
        sort($results);self::assertSame(['201','409'],$results);
        self::assertSame(0,(int)DB::table('library.book')->where('id',$book)->value('available_copies'));
        self::assertSame(1,DB::table('library.loan_item')->where('book_id',$book)->count());
        // Keep isolated fixtures for review; never delete live library records.
    }
    public function test_two_http_requests_receive_created_and_conflict(): void {
        if (DB::connection()->getDatabaseName() !== 'manager_library_verification') $this->markTestSkipped('Run only on isolated verification database');
        if (@file_get_contents('http://127.0.0.1:8001/api/v1/health') !== '{"status":"ok"}') $this->markTestSkipped('Start isolated API on port 8001');
        $book=(string)Str::uuid();$members=[(string)Str::uuid(),(string)Str::uuid()];$tokens=[];
        foreach($members as $member){
            DB::table('library.member')->insert(['id'=>$member,'full_name'=>'Prueba HTTP concurrente','document_number'=>'H-'.Str::random(20)]);
            $user=new \App\Domain\Entities\User((string)Str::uuid(),'race_http_'.strtolower(Str::random(12)),password_hash(Str::random(24),PASSWORD_BCRYPT),'librarian');
            DB::table('library.user')->insert(['id'=>$user->id,'username'=>$user->username,'password_hash'=>$user->passwordHash,'role'=>$user->role]);
            $tokens[]=app(\App\Infrastructure\Security\HmacTokenIssuer::class)->issue($user,28800);
        }
        DB::table('library.book')->insert(['id'=>$book,'title'=>'Carrera HTTP '.Str::random(12),'author'=>'Prueba','isbn'=>(string)random_int(1000000000000,9999999999999),'category_id'=>'11111111-1111-4111-8111-111111111111','total_copies'=>1,'available_copies'=>1]);
        $barrier=sys_get_temp_dir().'/library-http-race-'.Str::uuid();$processes=[];
        foreach($members as $index=>$member){
            $process=new Process([PHP_BINARY,base_path('tests/Support/concurrent-http-worker.php'),$barrier,(string)$index],base_path());
            $process->setInput(json_encode(['token'=>$tokens[$index],'body'=>['member_id'=>$member,'items'=>[['book_id'=>$book]]]]));
            $process->setTimeout(30);$process->start();$processes[]=$process;
        }
        $deadline=microtime(true)+20;
        while(!file_exists($barrier.'.0.ready') || !file_exists($barrier.'.1.ready')){if(microtime(true)>$deadline){foreach($processes as $process)$process->stop();self::fail('HTTP workers did not reach barrier');}usleep(10000);}
        touch($barrier.'.go');$results=[];
        foreach($processes as $process){$process->wait();self::assertTrue($process->isSuccessful());$results[]=trim($process->getOutput());}
        sort($results);self::assertSame(['201','409'],$results);
        self::assertSame(1,DB::table('library.loan_item')->where('book_id',$book)->count());
    }
}
