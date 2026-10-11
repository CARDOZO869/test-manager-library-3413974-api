<?php
declare(strict_types=1);
namespace App\Http;

use DomainException;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrorResponse {
    public static function render(Throwable $error): \Illuminate\Http\JsonResponse {
        $code = 'INTERNAL_ERROR'; $status = 500;
        $message = 'No se pudo completar la operación.'; $details = [];
        if ($error instanceof ValidationException) {
            $code = 'VALIDATION_ERROR'; $status = 422; $message = 'Revisa los campos enviados.';
            foreach (array_keys($error->errors()) as $field) {
                $details[] = ['field' => $field, 'issue' => 'valor inválido'];
            }
        } elseif ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
            $reason = explode(':', $error->getMessage())[0];
            $map = [
                'INVALID_CREDENTIALS' => [401, 'INVALID_CREDENTIALS', 'Usuario o contraseña incorrectos.'],
                'UNAUTHENTICATED' => [401, 'UNAUTHENTICATED', 'Inicia sesión para continuar.'],
                'FORBIDDEN' => [403, 'FORBIDDEN_ROLE', 'Tu rol no permite esta operación.'],
                'USERNAME_TAKEN' => [409, 'USERNAME_TAKEN', 'El nombre de usuario ya existe.'],
                'ISBN_TAKEN' => [409, 'ISBN_TAKEN', 'El ISBN ya existe entre los libros activos.'],
                'DOCUMENT_TAKEN' => [409, 'DOCUMENT_TAKEN', 'El documento ya está registrado.'],
                'BOOK_OUT_OF_STOCK' => [409, 'BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles.'],
                'Not enough available copies.' => [409, 'BOOK_NOT_AVAILABLE', 'No quedan ejemplares disponibles.'],
                'MEMBER_HAS_OVERDUE_ITEMS' => [409, 'MEMBER_HAS_OVERDUE', 'El socio tiene libros vencidos.'],
                'ITEM_ALREADY_RETURNED' => [409, 'ITEM_ALREADY_RETURNED', 'Esta línea ya fue devuelta.'],
                'Book with open loans cannot be deleted.' => [409, 'BOOK_HAS_OPEN_LOANS', 'El libro tiene préstamos abiertos.'],
                'MEMBER_LOAN_LIMIT' => [422, 'MEMBER_LIMIT_EXCEEDED', 'El socio no puede superar tres libros sin devolver.'],
                'DUPLICATE_BOOK_OR_ITEM' => [422, 'DUPLICATE_BOOK_IN_LOAN', 'No repitas libros en el préstamo.'],
                'DUPLICATE_BOOK_IN_LOAN' => [422, 'DUPLICATE_BOOK_IN_LOAN', 'No repitas libros en el préstamo.'],
                'INVALID_DATE_RANGE' => [422, 'INVALID_DATE_RANGE', 'La fecha final debe ser posterior o igual a la inicial.'],
                'BOOK_CHANGED_RETRY' => [409, 'BOOK_NOT_AVAILABLE', 'El catálogo cambió. Intenta de nuevo.'],
            ];
            if (str_ends_with($reason, '_NOT_FOUND')) {
                [$status, $code, $message] = [404, 'NOT_FOUND', 'El recurso no existe.'];
            } else {
                [$status, $code, $message] = $map[$reason] ?? [422, 'VALIDATION_ERROR', 'La operación no cumple las reglas de validación.'];
            }
            if ($reason === 'MEMBER_LOAN_LIMIT') {
                $details[] = ['field' => 'items', 'issue' => 'libros sin devolver: '.explode(':', $error->getMessage())[1]];
            }
        } elseif ($error instanceof QueryException && (string)$error->getCode() === '23505') {
            $sql = $error->getSql();
            $code = str_contains($sql, 'library.book') ? 'ISBN_TAKEN' : (str_contains($sql, 'library.member') ? 'DOCUMENT_TAKEN' : 'USERNAME_TAKEN');
            $status = 409; $message = 'El valor único ya está registrado.';
        } elseif ($error instanceof HttpExceptionInterface) {
            $status = $error->getStatusCode();
            $code = $status === 404 ? 'NOT_FOUND' : 'HTTP_ERROR';
            $message = $status === 404 ? 'El recurso no existe.' : 'No se pudo completar la solicitud.';
        }
        return response()->json(compact('code', 'message', 'details'), $status);
    }
}
