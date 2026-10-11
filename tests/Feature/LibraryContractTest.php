<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Domain\Entities\User;
use App\Infrastructure\Security\HmacTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LibraryContractTest extends TestCase {
    private string $admin;
    private string $librarian;
    private string $username;
    private string $password;

    protected function setUp(): void {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') $this->markTestSkipped('Requires PostgreSQL');
        DB::beginTransaction();
        $this->username = 'contract_'.strtolower(Str::random(12));
        $this->password = Str::random(24);
        foreach (['admin', 'librarian'] as $role) {
            $user = new User((string)Str::uuid(), $this->username.$role, password_hash($this->password, PASSWORD_BCRYPT), $role);
            DB::table('library.user')->insert(['id'=>$user->id, 'username'=>$user->username, 'password_hash'=>$user->passwordHash, 'role'=>$role]);
            $this->{$role} = app(HmacTokenIssuer::class)->issue($user, 28800);
        }
    }
    protected function tearDown(): void {
        if (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }
    private function headers(string $role = 'admin'): array { return ['Authorization'=>'Bearer '.$this->{$role}]; }
    private function book(): array {
        return $this->postJson('/api/v1/books', [
            'title'=>'Contrato '.Str::random(12), 'author'=>'Autor', 'isbn'=>(string)random_int(1000000000000, 9999999999999),
            'category_id'=>'11111111-1111-4111-8111-111111111111', 'total_copies'=>1,
        ], $this->headers())->assertCreated()->json();
    }
    public function test_login_matches_contract_and_never_exposes_password(): void {
        $response = $this->postJson('/api/v1/auth/login', ['username'=>' '.strtoupper($this->username.'admin').' ', 'password'=>$this->password]);
        $response->assertOk()->assertJsonStructure(['access_token','token_type','expires_in','role'])->assertJsonPath('expires_in',28800)->assertJsonPath('role','admin');
        self::assertStringNotContainsString($this->password, $response->getContent());
        $this->postJson('/api/v1/auth/login',['username'=>$this->username,'password'=>'invalid'])->assertUnauthorized()->assertJsonPath('code','INVALID_CREDENTIALS')->assertJsonStructure(['code','message','details']);
    }
    public function test_all_private_routes_reject_missing_token(): void {
        $id=(string)Str::uuid();
        foreach (['/categories','/books','/books/'.$id,'/books/'.$id.'/cover','/members','/members/'.$id,'/loans','/loans/overdue','/loans/'.$id,'/reports/most-borrowed'] as $route) {
            $this->getJson('/api/v1'.$route)->assertUnauthorized()->assertJsonStructure(['code','message','details']);
        }
        foreach (['/users','/books','/members','/loans','/loans/'.$id.'/items/'.$id.'/return'] as $route) {
            $this->postJson('/api/v1'.$route,[])->assertUnauthorized();
        }
        foreach (['/books/'.$id,'/books/'.$id.'/cover'] as $route) {
            $this->putJson('/api/v1'.$route,[])->assertUnauthorized();
            $this->deleteJson('/api/v1'.$route)->assertUnauthorized();
        }
    }
    public function test_role_is_checked_before_resource_or_input(): void {
        $this->postJson('/api/v1/books',[], $this->headers('librarian'))->assertForbidden()->assertJsonPath('code','FORBIDDEN_ROLE');
        $this->postJson('/api/v1/users',[], $this->headers('librarian'))->assertForbidden();
        $this->getJson('/api/v1/reports/most-borrowed', $this->headers('librarian'))->assertForbidden();
        $this->getJson('/api/v1/loans/overdue', $this->headers())->assertOk()->assertJsonStructure(['items']);
    }
    public function test_book_member_loan_return_and_report_flow(): void {
        $book=$this->book();
        $this->getJson('/api/v1/books?q='.urlencode($book['title']),$this->headers())->assertOk()->assertJsonPath('total',1)->assertJsonPath('items.0.id',$book['id']);
        $this->postJson('/api/v1/books', ['title'=>'Otro','author'=>'Autor','isbn'=>$book['isbn'],'category_id'=>$book['category']['id'],'total_copies'=>1],$this->headers())->assertConflict()->assertJsonPath('code','ISBN_TAKEN');
        $member=$this->postJson('/api/v1/members',['full_name'=>'Contrato socio','document_number'=>'C-'.Str::random(12)],$this->headers('librarian'))->assertCreated()->json();
        $body=['member_id'=>$member['id'],'items'=>[['book_id'=>$book['id']]]];
        $this->postJson('/api/v1/loans',['member_id'=>$member['id'],'items'=>[$body['items'][0],$body['items'][0]]],$this->headers())->assertUnprocessable()->assertJsonPath('code','DUPLICATE_BOOK_IN_LOAN');
        $loan=$this->postJson('/api/v1/loans',$body,$this->headers('librarian'))->assertCreated()->assertJsonPath('status','open')->json();
        self::assertSame(14,(new \DateTimeImmutable($loan['loaned_at']))->diff(new \DateTimeImmutable($loan['items'][0]['due_at']))->days);
        $this->postJson('/api/v1/loans',$body,$this->headers())->assertConflict()->assertJsonPath('code','BOOK_NOT_AVAILABLE');
        $this->getJson('/api/v1/members/'.$member['id'],$this->headers())->assertJsonPath('active_loans',1);
        $this->deleteJson('/api/v1/books/'.$book['id'],[],$this->headers())->assertConflict()->assertJsonPath('code','BOOK_HAS_OPEN_LOANS');
        $return='/api/v1/loans/'.$loan['id'].'/items/'.$loan['items'][0]['id'].'/return';
        $this->postJson($return,[],$this->headers())->assertOk();
        $this->postJson($return,[],$this->headers())->assertConflict()->assertJsonPath('code','ITEM_ALREADY_RETURNED');
        $this->getJson('/api/v1/loans/'.$loan['id'],$this->headers())->assertJsonPath('status','closed');
        $this->getJson('/api/v1/books/'.$book['id'],$this->headers())->assertJsonPath('available_copies',1);
        $this->putJson('/api/v1/books/'.$book['id'],['title'=>'Renombrado','author'=>'Autor','category_id'=>$book['category']['id'],'total_copies'=>1],$this->headers())->assertOk();
        $today=gmdate('Y-m-d');
        $rows=$this->getJson('/api/v1/reports/most-borrowed?from='.$today.'&to='.$today,$this->headers())->assertOk()->json('rows');
        $row=array_values(array_filter($rows,fn($r)=>$r['book_id']===$book['id']))[0];
        self::assertSame($book['title'],$row['book_title']);
        self::assertSame(1,$row['times_borrowed']);
        $this->deleteJson('/api/v1/books/'.$book['id'],[],$this->headers())->assertNoContent();
        $this->getJson('/api/v1/books/'.$book['id'],$this->headers())->assertNotFound()->assertJsonPath('code','NOT_FOUND');
    }
    public function test_validation_and_reversed_dates_have_safe_errors(): void {
        $this->postJson('/api/v1/users',['username'=>'x','password'=>'x','role'=>'admin'],$this->headers())->assertUnprocessable()->assertJsonPath('code','VALIDATION_ERROR');
        $this->getJson('/api/v1/reports/most-borrowed?from=2026-10-11&to=2026-10-10',$this->headers())->assertUnprocessable()->assertJsonPath('code','INVALID_DATE_RANGE');
        $this->getJson('/api/v1/books?size=51',$this->headers())->assertUnprocessable();
    }
    public function test_password_adapter_uses_argon2id_and_accepts_existing_bcrypt(): void {
        $hasher=app(\App\Application\Ports\PasswordHasher::class);
        $hash=$hasher->hash($this->password);
        self::assertSame('argon2id',password_get_info($hash)['algoName']);
        self::assertTrue($hasher->verify($this->password,$hash));
        self::assertTrue($hasher->verify($this->password,password_hash($this->password,PASSWORD_BCRYPT)));
    }
    public function test_cover_upload_read_remove_and_size_validation(): void {
        \Illuminate\Support\Facades\Storage::fake('local');
        $book=$this->book();$route='/api/v1/books/'.$book['id'].'/cover';
        $this->getJson($route,$this->headers())->assertNotFound();
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
        $file=\Illuminate\Http\UploadedFile::fake()->createWithContent('cover.png',$png);
        $response=$this->call('PUT',$route,[],[],['file'=>$file],['HTTP_AUTHORIZATION'=>'Bearer '.$this->admin,'HTTP_ACCEPT'=>'application/json']);
        $response->assertOk();$key=$response->json('cover_key');
        self::assertMatchesRegularExpression('~^covers/[0-9a-f-]{36}\.png$~',$key);
        $this->getJson($route,$this->headers())->assertOk()->assertHeader('Content-Type','image/png');
        $this->deleteJson($route,[],$this->headers())->assertNoContent();
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($key);
        $this->getJson('/api/v1/books/'.$book['id'],$this->headers())->assertJsonPath('cover_key',null);
        $large=\Illuminate\Http\UploadedFile::fake()->create('large.png',3072,'image/png');
        $this->call('PUT',$route,[],[],['file'=>$large],['HTTP_AUTHORIZATION'=>'Bearer '.$this->admin,'HTTP_ACCEPT'=>'application/json'])->assertUnprocessable()->assertJsonPath('code','VALIDATION_ERROR');
    }
    public function test_schema_has_six_tables_no_defaults_and_required_checks(): void {
        $tables=DB::table('information_schema.tables')->where('table_schema','library')->where('table_type','BASE TABLE')->pluck('table_name')->sort()->values()->all();
        self::assertSame(['book','category','loan','loan_item','member','user'],$tables);
        self::assertSame(0,DB::table('information_schema.columns')->where('table_schema','library')->whereNotNull('column_default')->count());
        $names=array_column(DB::select("SELECT c.conname FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname='library' AND c.contype='c'"),'conname');
        foreach(['ck_book_total_positive','ck_book_available_range','ck_user_role'] as $name) self::assertContains($name,$names);
        self::assertSame(5,DB::table('library.category')->count());
    }
    public function test_duplicate_user_and_member_have_official_conflicts(): void {
        $input=['username'=>' HTTP_'.Str::random(12).' ','password'=>Str::random(24),'role'=>'librarian'];
        $created=$this->postJson('/api/v1/users',$input,$this->headers())->assertCreated()->json();
        self::assertSame(strtolower(trim($input['username'])),$created['username']);
        self::assertArrayNotHasKey('password',$created);self::assertArrayNotHasKey('password_hash',$created);
        $this->postJson('/api/v1/users',$input,$this->headers())->assertConflict()->assertJsonPath('code','USERNAME_TAKEN');
        $member=['full_name'=>'Prueba duplicado','document_number'=>'D-'.Str::random(20)];
        $this->postJson('/api/v1/members',$member,$this->headers())->assertCreated();
        $this->postJson('/api/v1/members',$member,$this->headers())->assertConflict()->assertJsonPath('code','DOCUMENT_TAKEN');
    }
    public function test_member_limit_and_overdue_have_official_errors(): void {
        $books=[$this->book(),$this->book(),$this->book(),$this->book()];
        $member=$this->postJson('/api/v1/members',['full_name'=>'Prueba límites','document_number'=>'L-'.Str::random(20)],$this->headers())->assertCreated()->json();
        $loan=$this->postJson('/api/v1/loans',['member_id'=>$member['id'],'items'=>array_map(fn($book)=>['book_id'=>$book['id']],array_slice($books,0,3))],$this->headers())->assertCreated()->json();
        $next=['member_id'=>$member['id'],'items'=>[['book_id'=>$books[3]['id']]]];
        $this->postJson('/api/v1/loans',$next,$this->headers())->assertUnprocessable()->assertJsonPath('code','MEMBER_LIMIT_EXCEEDED')->assertJsonPath('details.0.issue','libros sin devolver: 3');
        DB::table('library.loan_item')->where('loan_id',$loan['id'])->update(['due_at'=>gmdate('Y-m-d H:i:s',time()-86400)]);
        $this->postJson('/api/v1/loans',$next,$this->headers())->assertConflict()->assertJsonPath('code','MEMBER_HAS_OVERDUE');
    }
    public function test_engine_rejects_invalid_total_and_role(): void {
        $invalidBook=['id'=>(string)Str::uuid(),'title'=>'Inválido','author'=>'Prueba','isbn'=>(string)random_int(1000000000000,9999999999999),'category_id'=>'11111111-1111-4111-8111-111111111111','total_copies'=>0,'available_copies'=>0];
        $invalidUser=['id'=>(string)Str::uuid(),'username'=>'invalid_'.strtolower(Str::random(12)),'password_hash'=>password_hash(Str::random(24),PASSWORD_BCRYPT),'role'=>'student'];
        foreach([['library.book',$invalidBook],['library.user',$invalidUser]] as [$table,$row]) {
            try { DB::transaction(fn()=>DB::table($table)->insert($row)); self::fail('Expected engine check violation'); }
            catch(\Illuminate\Database\QueryException $error) { self::assertSame('23514',$error->errorInfo[0]); }
        }
    }
}
