<?php
namespace App\Helpers;

class ApiFormatter{
    public static function createApi($code = 200, $message = null, $data = null, $errors = null, $meta = null)
    {
        return response()->json([
            'code' => $code,
            'success' => $code >= 200 && $code < 300,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ], $code);
    }
 }
