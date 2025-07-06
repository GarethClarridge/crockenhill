<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
  /**
   * A list of the exception types that are not reported.
   *
   * @var array<int, class-string<Throwable>>
   */
  protected $dontReport = [
    //
  ];

  /**
   * A list of the inputs that are never flashed for validation exceptions.
   *
   * @var array<int, string>
   */
  protected $dontFlash = [
    'password',
    'password_confirmation',
  ];

  /**
   * Report or log an exception.
   *
   * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
   *
   * @param  \Exception  $exception
   * @return void
   */
  public function report(Throwable $exception)
  {
    parent::report($exception);
  }

  /**
   * Render an exception into an HTTP response.
   *
   * @param  \Illuminate\Http\Request  $request
   * @param  \Throwable  $exception
   * @return \Illuminate\Http\Response
   */
  public function render($request, Throwable $exception)
  {
    $response = parent::render($request, $exception);

    // Ensure the response is an instance of Illuminate\Http\Response
    if (!$response instanceof \Illuminate\Http\Response) {
      return response($response->getContent(), $response->getStatusCode(), $response->headers->all());
    }

    return $response;
  }
}
