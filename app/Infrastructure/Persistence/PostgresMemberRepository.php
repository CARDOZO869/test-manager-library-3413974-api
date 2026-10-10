<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence;
use App\Domain\Entities\Member;
use Illuminate\Support\Facades\DB;
final class PostgresMemberRepository {
    public function findById(string $id): ?Member {
        $row = DB::table('library.member')->where('id', $id)->first();
        return $row === null ? null : new Member($row->id, $row->full_name, $row->document_number);
    }
    public function findByDocument(string $document): ?Member {
        $row = DB::table('library.member')->where('document_number', trim($document))->first();
        return $row === null ? null : new Member($row->id, $row->full_name, $row->document_number);
    }
    public function save(Member $member): void {
        DB::table('library.member')->upsert([[
            'id' => $member->id, 'full_name' => $member->fullName,
            'document_number' => $member->documentNumber,
        ]], ['id'], ['full_name','document_number']);
    }
}