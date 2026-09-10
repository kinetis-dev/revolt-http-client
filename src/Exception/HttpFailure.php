<?php

declare(strict_types=1);

namespace Kinetis\RevoltHttpClient\Exception;

/**
 * The fixed set of categories {@see HttpRequestException} reports, chosen
 * at the point of failure and never derived from a vendor exception's
 * class or message. Branch on the category rather than on
 * `getMessage()`, which is prose.
 */
enum HttpFailure: string
{
    /**
     * A client, a call, or a body this package refuses to send, the
     * transport's own refusal to construct a request included. Nothing
     * reached the network, and a repeat would refuse the same way.
     */
    case InvalidRequest = 'invalid-request';

    /** Reading a response as JSON failed. */
    case Conversion = 'conversion';

    /**
     * No complete response arrived: DNS, a refused connection, a dropped
     * socket. The server may still have received and applied the request.
     */
    case Transport = 'transport';

    /** The total timeout for the operation ran out. */
    case Timeout = 'timeout';

    /** The response body passed the ceiling the client allows it. */
    case ResponseTooLarge = 'response-too-large';

    /** A response arrived, and `HttpResponse::throw()` was asked to raise on it. */
    case ErrorStatus = 'error-status';

    /** A read was attempted on a response whose body had already been released. */
    case Discarded = 'discarded';
}
