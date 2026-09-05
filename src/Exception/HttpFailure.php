<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Exception;

/**
 * The fixed set of categories {@see HttpRequestException} reports.
 *
 * A category is the whole machine-readable answer to "what went wrong":
 * it is chosen from this list at the point of failure, never derived from
 * a vendor exception's class or message, so no lower-level library's own
 * wording can reach a caller through it. Branch on the category rather
 * than on `getMessage()`, which is prose and carries only the request
 * method, the validated origin, and a status.
 */
enum HttpFailure: string
{
    /** A client built with input this package refuses to send. */
    case InvalidConfiguration = 'invalid-configuration';

    /** A per-call URL, header, query, body, or option this package refuses to send. */
    case InvalidRequest = 'invalid-request';

    /** Encoding a body, or reading a response as JSON, failed. */
    case Conversion = 'conversion';

    /** No response arrived: DNS, a refused connection, a dropped socket. */
    case Transport = 'transport';

    /** The total timeout for the operation ran out. */
    case Timeout = 'timeout';

    /** The response body passed the ceiling the client allows it. */
    case ResponseTooLarge = 'response-too-large';

    /** A response arrived, and {@see HttpRequestException::errorStatus()} was asked to treat it as a failure. */
    case ErrorStatus = 'error-status';

    /** A method was called on a response whose body had already been released. */
    case Discarded = 'discarded';
}
