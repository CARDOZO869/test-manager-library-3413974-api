<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;
use App\Application\Services\BookCatalogService;
use App\Infrastructure\Security\AuthenticatedUserResolver;
use App\Infrastructure\Persistence\PostgresMemberRepository;
use App\Domain\Entities\Member;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class CatalogManagementController {
    private function role(Request $request, array $roles): string {
        $resolver=app(AuthenticatedUserResolver::class);
        $user=$resolver->resolve($request->header('Authorization',''));
        $resolver->requireRole($user,$roles);
        return $user->role;
    }
    private function book(\App\Domain\Entities\Book $book): array {
        $category=DB::table('library.category')->where('id',$book->categoryId)->first();
        return ['id'=>$book->id,'title'=>$book->title,'author'=>$book->author,'isbn'=>$book->isbn->value,
            'category'=>['id'=>$book->categoryId,'name'=>$category?->name],
            'total_copies'=>$book->totalCopies,'available_copies'=>$book->availableCopies,'cover_key'=>$book->coverKey];
    }
    public function createBook(Request $request,BookCatalogService $service): JsonResponse {
        $role=$this->role($request,['admin']);
        $data=$request->validate(['title'=>'required|string|max:250','author'=>'required|string|max:200',
            'isbn'=>['required','regex:/^[0-9]{13}$/D'],'category_id'=>'required|uuid|exists:library.category,id',
            'total_copies'=>'required|integer|min:1']);
        $book=$service->create($role,(string)Str::uuid(),$data['title'],$data['author'],$data['isbn'],$data['category_id'],(int)$data['total_copies']);
        return response()->json($this->book($book),201);
    }
    public function updateBook(Request $request,string $id,BookCatalogService $service): array {
        $role=$this->role($request,['admin']);
        $data=$request->validate(['title'=>'required|string|max:250','author'=>'required|string|max:200',
            'category_id'=>'required|uuid|exists:library.category,id','total_copies'=>'required|integer|min:1']);
        return $this->book($service->update($role,$id,$data['title'],$data['author'],$data['category_id'],(int)$data['total_copies']));
    }
    public function deleteBook(Request $request,string $id,BookCatalogService $service): \Illuminate\Http\Response {
        $role=$this->role($request,['admin']);
        $service->delete($role,$id,new DateTimeImmutable('now',new DateTimeZone('UTC')));
        return response()->noContent();
    }
    public function members(Request $request): array {
        $this->role($request,['admin','librarian']);
        $data=$request->validate(['q'=>'nullable|string|max:200','page'=>'nullable|integer|min:1','size'=>'nullable|integer|min:1|max:50']);
        $page=(int)($data['page']??1);$size=(int)($data['size']??20);
        $query=DB::table('library.member');
        if (!empty($data['q'])) {
            $term='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$data['q']).'%';
            $query->where(static fn($q)=>$q->where('full_name','ilike',$term)->orWhere('document_number','ilike',$term));
        }
        $total=(clone $query)->count();
        $items=$query->orderBy('full_name')->offset(($page-1)*$size)->limit($size)->get(['id','full_name','document_number'])->all();
        return ['items'=>$items,'page'=>$page,'size'=>$size,'total'=>$total];
    }
    public function member(Request $request,string $id,PostgresMemberRepository $members): array {
        $this->role($request,['admin','librarian']);
        $member=$members->findById($id);
        if ($member===null) throw new DomainException('MEMBER_NOT_FOUND');
        $active=DB::table('library.loan_item as li')->join('library.loan as l','l.id','=','li.loan_id')
            ->where('l.member_id',$id)->whereNull('li.returned_at')->count();
        return ['id'=>$member->id,'full_name'=>$member->fullName,'document_number'=>$member->documentNumber,'active_loans'=>$active];
    }
    public function createMember(Request $request,PostgresMemberRepository $members): JsonResponse {
        $this->role($request,['admin','librarian']);
        $data=$request->validate(['full_name'=>'required|string|max:200','document_number'=>'required|string|max:30']);
        if ($members->findByDocument($data['document_number'])!==null) throw new DomainException('DOCUMENT_TAKEN');
        $member=new Member((string)Str::uuid(),$data['full_name'],$data['document_number']);
        $members->save($member);
        return response()->json(['id'=>$member->id,'full_name'=>$member->fullName,'document_number'=>$member->documentNumber],201);
    }
}
