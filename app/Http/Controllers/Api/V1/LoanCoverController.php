<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;
use App\Application\Services\BookCatalogService;
use App\Application\Services\BookCoverService;
use App\Application\Ports\CoverStorage;
use App\Infrastructure\Persistence\PostgresLoanRepository;
use App\Infrastructure\Security\AuthenticatedUserResolver;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
final class LoanCoverController {
    private function role(Request $request,array $roles): string {
        $resolver=app(AuthenticatedUserResolver::class);
        $user=$resolver->resolve($request->header('Authorization',''));
        $resolver->requireRole($user,$roles);
        return $user->role;
    }
    private function utc(DateTimeImmutable $date): string {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
    private function loan(\App\Domain\Entities\Loan $loan): array {
        $now=app(\App\Application\Ports\Clock::class)->now();
        return ['id'=>$loan->id,'member_id'=>$loan->memberId,'loaned_at'=>$this->utc($loan->loanedAt),
            'status'=>$loan->status($now),'items'=>array_map(fn($item)=>[
                'id'=>$item->id,'book_id'=>$item->bookId,'book_title'=>$item->bookTitle,
                'isbn'=>$item->isbn,'category_name'=>$item->categoryName,
                'due_at'=>$this->utc($item->dueAt),
                'returned_at'=>$item->returnedAt()===null?null:$this->utc($item->returnedAt()),
            ],$loan->items())];
    }
    public function loans(Request $request,PostgresLoanRepository $repo): array {
        $this->role($request,['admin','librarian']);
        $data=$request->validate(['member_id'=>'nullable|uuid','status'=>'nullable|in:open,overdue,closed',
            'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d',
            'page'=>'nullable|integer|min:1','size'=>'nullable|integer|min:1|max:50']);
        $page=(int)($data['page']??1);$size=(int)($data['size']??20);
        $query=DB::table('library.loan');
        if (!empty($data['member_id'])) $query->where('member_id',$data['member_id']);
        if (!empty($data['from'])) $query->where('loaned_at','>=',$data['from'].' 00:00:00+00');
        if (!empty($data['to'])) $query->where('loaned_at','<',(new DateTimeImmutable($data['to'],new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d').' 00:00:00+00');
        if (!empty($data['status'])) {
            $now=(app(\App\Application\Ports\Clock::class)->now())->format('Y-m-d H:i:s.uP');
            if ($data['status']==='closed') $query->whereNotExists(static fn($q)=>$q->selectRaw('1')->from('library.loan_item as li')->whereColumn('li.loan_id','library.loan.id')->whereNull('li.returned_at'));
            if ($data['status']==='overdue') $query->whereExists(static fn($q)=>$q->selectRaw('1')->from('library.loan_item as li')->whereColumn('li.loan_id','library.loan.id')->whereNull('li.returned_at')->where('li.due_at','<',$now));
            if ($data['status']==='open') $query->whereExists(static fn($q)=>$q->selectRaw('1')->from('library.loan_item as li')->whereColumn('li.loan_id','library.loan.id')->whereNull('li.returned_at'))->whereNotExists(static fn($q)=>$q->selectRaw('1')->from('library.loan_item as li')->whereColumn('li.loan_id','library.loan.id')->whereNull('li.returned_at')->where('li.due_at','<',$now));
        }
        $total=(clone $query)->count();
        $ids=$query->orderByDesc('loaned_at')->offset(($page-1)*$size)->limit($size)->pluck('id');
        $items=[];
        foreach($ids as $id) {
            $loan=$repo->findLoan($id);
            if ($loan!==null) $items[]=$this->loan($loan);
        }
        return ['items'=>$items,'page'=>$page,'size'=>$size,'total'=>$total];
    }
    public function detail(Request $request,string $id,PostgresLoanRepository $repo): array {
        $this->role($request,['admin','librarian']);
        $loan=$repo->findLoan($id);
        if ($loan===null) throw new DomainException('LOAN_NOT_FOUND');
        return $this->loan($loan);
    }
    public function uploadCover(Request $request,string $id,BookCoverService $service): array {
        $role=$this->role($request,['admin']);
        $request->validate(['file'=>'required|file|max:2048|mimes:jpg,jpeg,png']);
        $bytes=file_get_contents($request->file('file')->getRealPath());
        if ($bytes===false) throw new DomainException('INVALID_COVER_FILE');
        return ['cover_key'=>$service->upload($role,$id,$bytes)];
    }
    public function removeCover(Request $request,string $id,BookCoverService $covers): \Illuminate\Http\Response {
        $role=$this->role($request,['admin']);
        $covers->remove($role,$id);
        return response()->noContent();
    }
    public function getCover(Request $request,string $id,BookCatalogService $books,CoverStorage $storage): \Symfony\Component\HttpFoundation\Response {
        $this->role($request,['admin','librarian']);
        $book=$books->get($id);
        if ($book->coverKey===null) throw new DomainException('COVER_NOT_FOUND');
        $bytes=$storage->get($book->coverKey);
        if ($bytes===null) throw new DomainException('COVER_NOT_FOUND');
        $type=str_ends_with($book->coverKey,'.png')?'image/png':'image/jpeg';
        return response($bytes,200,['Content-Type'=>$type,'Cache-Control'=>'private, max-age=300']);
    }
}
