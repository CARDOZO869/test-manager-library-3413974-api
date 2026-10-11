<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LibraryDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $categories = DB::table('library.category')
                ->orderBy('id')
                ->pluck('id')
                ->values()
                ->all();

            if (count($categories) < 5) {
                throw new RuntimeException('Se requieren cinco categorias.');
            }

            $librarian = DB::table('library.user')
                ->whereIn('role', ['admin', 'librarian'])
                ->first();

            if ($librarian === null) {
                throw new RuntimeException(
                    'Primero debe existir un administrador o bibliotecario.'
                );
            }

            $bookIds = [];
            $memberIds = [];

            for ($i = 1; $i <= 40; $i++) {
                $isbn = sprintf('978000%07d', $i);

                $existing = DB::table('library.book')
                    ->where('isbn', $isbn)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existing !== null) {
                    $bookIds[] = $existing->id;
                    continue;
                }

                $id = (string) Str::uuid();

                DB::table('library.book')->insert([
                    'id' => $id,
                    'title' => sprintf('Libro de demostracion %02d', $i),
                    'author' => sprintf('Autor de prueba %02d', $i),
                    'isbn' => $isbn,
                    'category_id' => $categories[($i - 1) % 5],
                    'total_copies' => 5,
                    'available_copies' => 5,
                    'cover_key' => null,
                    'deleted_at' => null,
                ]);

                $bookIds[] = $id;
            }

            for ($i = 1; $i <= 10; $i++) {
                $document = sprintf('DEMO%06d', $i);

                $existing = DB::table('library.member')
                    ->where('document_number', $document)
                    ->first();

                if ($existing !== null) {
                    $memberIds[] = $existing->id;
                    continue;
                }

                $id = (string) Str::uuid();

                DB::table('library.member')->insert([
                    'id' => $id,
                    'full_name' => sprintf('Miembro de demostracion %02d', $i),
                    'document_number' => $document,
                ]);

                $memberIds[] = $id;
            }

            for ($i = 1; $i <= 15; $i++) {
                $bookId = $bookIds[$i - 1];
                $memberId = $memberIds[($i - 1) % 10];

                $alreadySeeded = DB::table('library.loan_item')
                    ->where('book_id', $bookId)
                    ->where('book_title', sprintf('Libro de demostracion %02d', $i))
                    ->exists();

                if ($alreadySeeded) {
                    continue;
                }

                $book = DB::table('library.book')
                    ->where('id', $bookId)
                    ->first();

                if ($book === null || $book->available_copies < 1) {
                    throw new RuntimeException('No hay existencias para el libro ' . $i);
                }

                $loanedAt = $i <= 3
                    ? now()->subDays(25)
                    : now()->subDays(2);

                $loanId = (string) Str::uuid();

                DB::table('library.loan')->insert([
                    'id' => $loanId,
                    'member_id' => $memberId,
                    'loaned_by_user_id' => $librarian->id,
                    'loaned_at' => $loanedAt,
                ]);

                DB::table('library.loan_item')->insert([
                    'id' => (string) Str::uuid(),
                    'loan_id' => $loanId,
                    'book_id' => $bookId,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => DB::table('library.category')
                        ->where('id', $book->category_id)
                        ->value('name'),
                    'due_at' => $loanedAt->copy()->addDays(14),
                    'returned_at' => null,
                ]);

                DB::table('library.book')
                    ->where('id', $bookId)
                    ->decrement('available_copies');
            }
        });
    }
}