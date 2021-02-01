<?php

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\Sdk\Trace\Sampler\ParentBased;
use OpenTelemetry\Sdk\Trace\SamplingResult;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Sdk\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Sdk\Trace\TracerProvider;
use OpenTelemetry\Trace as API;

$sampler = new AlwaysOnSampler();
$samplingResult = $sampler->shouldSample(
    null,
    md5((string) microtime(true)),
    substr(md5((string) microtime(true)), 16),
    'io.opentelemetry.example',
    API\SpanKind::KIND_INTERNAL
);

$exporter = new JaegerExporter(
    'alwaysOnJaegerExample',
    'http://jaeger:9412/api/v2/spans'
);

if (SamplingResult::RECORD_AND_SAMPLED === $samplingResult->getDecision()) {
    echo 'Starting AlwaysOnJaegerExample';
    $tracer = (new TracerProvider())
        ->addSpanProcessor(new BatchSpanProcessor($exporter, Clock::get()))
        ->getTracer('io.opentelemetry.contrib.php');

    //Span one opens here
    $span = $tracer->startAndActivateSpan('one');

    //Span two opens here
    $tracer->startAndActivateSpan('two');

    //Span three opens here
    $span = $tracer->startAndActivateSpan('three');

    //Get the Trace id and last active span Id
    $traceId = $span->getContext()->getTraceId();
    $spanId =$span->getContext()->getSpanId();

    //Span three ends here
    $tracer->endActiveSpan();

    //Span two ends here
    $tracer->endActiveSpan();

    //Span one end here
    $tracer->endActiveSpan();

    echo "Trace ID : ".$traceId;
    echo "Span ID : ".$spanId;

    //Creating a parent based span using the Trace ID and the Id of the third Span
    //So parent-based span should come under third span, not as a root span
    $parentContext = new SpanContext(
        $traceId,
        $spanId,
        1
    );
    $root = new AlwaysOnSampler();
    $sampler = new ParentBased($root);

    $sampler->shouldSample(
        $parentContext,
        $traceId,
        $spanId,
        'one',
        API\SpanKind::KIND_INTERNAL
    );

    $span = $tracer->startAndActivateSpanFromContext('parent-based', $parentContext, true) ;
    $tracer->endActiveSpan();


    echo PHP_EOL . 'AlwaysOnJaegerExample complete!  See the results at http://localhost:16686/';
} else {
    echo PHP_EOL . 'AlwaysOnJaegerExample tracing is not enabled';
}

echo PHP_EOL;
