<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
if (DB::connection()->getDatabaseName() !== 'manager_library_verification') throw new RuntimeException('Requires isolated verification database');
$current=DB::table('library.book')->whereNull('deleted_at')->count();
for($i=$current;$i<5000;$i+=250){
    $rows=[];
    for($j=$i;$j<min($i+250,5000);$j++)$rows[]=['id'=>(string)Str::uuid(),'title'=>sprintf('Carga %05d',$j),'author'=>'Autor de carga','isbn'=>(string)(8800000000000+$j),'category_id'=>'11111111-1111-4111-8111-111111111111','total_copies'=>1,'available_copies'=>1];
    DB::table('library.book')->insert($rows);
}
function request(string $path,?array $body=null,?string $token=null): array {
    $headers="Accept: application/json\r\nContent-Type: application/json\r\n".($token?"Authorization: Bearer $token\r\n":'');
    $context=stream_context_create(['http'=>['method'=>$body?'POST':'GET','header'=>$headers,'content'=>$body?json_encode($body):'','ignore_errors'=>true,'timeout'=>30]]);
    $result=file_get_contents('http://127.0.0.1:8001/api/v1'.$path,false,$context);
    if(!str_contains($http_response_header[0],'200'))throw new RuntimeException('HTTP request failed');
    return json_decode($result,true,512,JSON_THROW_ON_ERROR);
}
$session=request('/auth/login',['username'=>getenv('LIBRARY_ADMIN_USERNAME'),'password'=>getenv('LIBRARY_ADMIN_PASSWORD')]);
for($i=0;$i<3;$i++) request('/books?q=Carga&page=1&size=20',null,$session['access_token']);
$times=[];
for($i=0;$i<20;$i++){
    $start=microtime(true);$response=request('/books?q=Carga&page=1&size=20',null,$session['access_token']);
    $times[]=(microtime(true)-$start)*1000;
}
sort($times);
echo json_encode(['active_books'=>DB::table('library.book')->whereNull('deleted_at')->count(),'warmup_requests'=>3,'samples'=>count($times),'median_ms'=>round($times[9],2),'p95_ms'=>round($times[18],2),'max_ms'=>round(max($times),2),'all_below_300_ms'=>max($times)<300,'fixture_source'=>'isolated SQL fixture; demo seeder tested separately over HTTP'],JSON_PRETTY_PRINT).PHP_EOL;
