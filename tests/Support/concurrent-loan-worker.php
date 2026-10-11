<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script,$member,$user,$bookId,$barrier,$index] = $argv;
$repo=$app->make(App\Infrastructure\Persistence\PostgresLoanRepository::class);
$book=$repo->findBook($bookId);
$loan=new App\Domain\Entities\Loan((string)Illuminate\Support\Str::uuid(),$member,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')));
$loan->addItem((string)Illuminate\Support\Str::uuid(),$bookId,$book->title,$book->isbn->value,$repo->categoryName($book->categoryId));
touch($barrier.'.'.$index.'.ready');
$deadline=microtime(true)+15;
while (!file_exists($barrier.'.go')) { if(microtime(true)>$deadline) exit(2); usleep(10000); }
try { $repo->commitLoan($loan,[$book]); echo '201'; }
catch (DomainException $error) { echo $error->getMessage()==='BOOK_OUT_OF_STOCK' ? '409' : 'unexpected'; }
