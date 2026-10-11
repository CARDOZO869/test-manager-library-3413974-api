<?php
declare(strict_types=1);
[$script,$barrier,$index]=$argv;
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
touch($barrier.'.'.$index.'.ready');
$deadline=microtime(true)+15;
while(!file_exists($barrier.'.go')){if(microtime(true)>$deadline)exit(2);usleep(10000);}
$context=stream_context_create(['http'=>['method'=>'POST','header'=>"Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer ".$input['token']."\r\n",'content'=>json_encode($input['body']),'ignore_errors'=>true,'timeout'=>20]]);
$raw=file_get_contents('http://127.0.0.1:8001/api/v1/loans',false,$context);
preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$match);
echo $match[1];
