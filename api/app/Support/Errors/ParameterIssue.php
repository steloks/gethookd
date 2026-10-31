<?php

namespace App\Support\Errors;

/**
 * The `issue` of one entry in a 422's details: what is wrong with that parameter.
 */
enum ParameterIssue: string
{
    case UnknownParameter = 'unknown_parameter';
    case Missing = 'missing';
    case ArraySyntax = 'array_syntax';
    case RepeatedParameter = 'repeated_parameter';
    case InvalidValue = 'invalid_value';
    case EmptyValue = 'empty_value';
    case OutOfRange = 'out_of_range';
    case TooLong = 'too_long';
    case CursorMismatch = 'cursor_mismatch';
}
