<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;
use App\Application\Services\LoginService;
use App\Application\Services\CreateLibrarianService;
use App\Application\Services\BookCatalogService;
use App\Application\Services\LendBooksService;
use App\Application\Services\ReturnBookService;
use App\Application\Services\ListOverdueService;
use App\Application\Services\MostBorrowedReportService;
use App\Infrastructure\Security\AuthenticatedUserResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use DateTimeImmutable;
final class LibraryController {
    private function actor(Request $request, array $roles): \App\Domain\Entities\User {
        $resolver=app(AuthenticatedUserResolver::class);
        $user=$resolver->resolve($request->header('Authorization',''));
        $resolver->requireRole($user,$roles);
        return $user;
    }
    private function utc(DateTimeImmutable $date): string {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    private function book(\App\Domain\Entities\Book $book, ?array $categories = null): array {
        $categoryName=$categories===null ? DB::table('library.category')->where('id',$book->categoryId)->value('name') : ($categories[$book->categoryId]??null);
        return ['id'=>$book->id,'title'=>$book->title,'author'=>$book->author,
            'isbn'=>$book->isbn->value,'category'=>['id'=>$book->categoryId,'name'=>$categoryName],
            'total_copies'=>$book->totalCopies,'available_copies'=>$book->availableCopies,'cover_key'=>$book->coverKey];
    }
    private function loan(\App\Domain\Entities\Loan $loan): array {
        $now=app(\App\Application\Ports\Clock::class)->now();
        return ['id'=>$loan->id,'member_id'=>$loan->memberId,'loaned_at'=>$this->utc($loan->loanedAt),
            'status'=>$loan->status($now),'items'=>array_map(fn ($i)=>[
                'id'=>$i->id,'book_id'=>$i->bookId,'book_title'=>$i->bookTitle,'isbn'=>$i->isbn,
                'category_name'=>$i->categoryName,'due_at'=>$this->utc($i->dueAt),
                'returned_at'=>$i->returnedAt()===null?null:$this->utc($i->returnedAt())],$loan->items())];
    }
    public function health(): array { return ['status'=>'ok']; }
    public function login(Request $request, LoginService $service): array {
        $data=$request->validate(['username'=>'required|string','password'=>'required|string']);
        return $service->execute($data['username'],$data['password']);
    }
    public function users(Request $request, CreateLibrarianService $service): \Illuminate\Http\JsonResponse {
        $actor=$this->actor($request,['admin']);
        $data=$request->validate(['username'=>'required|string|max:120','password'=>'required|string','role'=>'required|in:librarian']);
        $user=$service->execute($actor->role,(string)Str::uuid(),$data['username'],$data['password'],$data['role']);
        return response()->json(['id'=>$user->id,'username'=>$user->username,'role'=>$user->role],201);
    }
    public function categories(Request $request): array {
        $this->actor($request,['admin','librarian']);
        return DB::table('library.category')->orderBy('name')->get(['id','name'])->all();
    }
    public function books(Request $request, BookCatalogService $service): array {
        $this->actor($request,['admin','librarian']);
        $data=$request->validate(['q'=>'nullable|string','category_id'=>'nullable|uuid','page'=>'nullable|integer|min:1','size'=>'nullable|integer|min:1|max:50']);
        $page=(int)($data['page']??1);$size=(int)($data['size']??20);
        $result=$service->search($data['q']??null,$data['category_id']??null,$page,$size);
        $categories=DB::table('library.category')->pluck('name','id')->all();
        return ['items'=>array_map(fn ($book)=>$this->book($book,$categories),$result['items']),'page'=>$page,'size'=>$size,'total'=>$result['total']];
    }
    public function bookDetail(Request $request, string $id, BookCatalogService $service): array {
        $this->actor($request,['admin','librarian']);
        return $this->book($service->get($id));
    }
    public function createLoan(Request $request, LendBooksService $service): \Illuminate\Http\JsonResponse {
        $actor=$this->actor($request,['admin','librarian']);
        $data=$request->validate(['member_id'=>'required|uuid','items'=>'required|array|min:1|max:3',
            'items.*.book_id'=>'required|uuid']);
        $loan=$service->execute((string)Str::uuid(),$data['member_id'],$actor->id,
            array_column($data['items'],'book_id'),
            array_map(static fn()=> (string)Str::uuid(),$data['items']),
            app(\App\Application\Ports\Clock::class)->now());
        return response()->json($this->loan($loan),201);
    }
    public function returnItem(Request $request,string $loanId,string $itemId,ReturnBookService $service): array {
        $this->actor($request,['admin','librarian']);
        $item=$service->execute($loanId,$itemId,app(\App\Application\Ports\Clock::class)->now());
        return ['id'=>$item->id,'book_id'=>$item->bookId,'book_title'=>$item->bookTitle,
            'isbn'=>$item->isbn,'category_name'=>$item->categoryName,'due_at'=>$this->utc($item->dueAt),
            'returned_at'=>$item->returnedAt()===null?null:$this->utc($item->returnedAt())];
    }
    public function overdue(Request $request,ListOverdueService $service): array {
        $this->actor($request,['admin','librarian']);
        $rows=$service->execute(app(\App\Application\Ports\Clock::class)->now());
        return ['items'=>array_map(fn($row)=>array_merge($row,['due_at'=>$this->utc($row['due_at'])]),$rows)];
    }
    public function report(Request $request,MostBorrowedReportService $service): array {
        $this->actor($request,['admin']);
        $data=$request->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d']);
        return $service->execute('admin',$data['from'],$data['to']);
    }
}
