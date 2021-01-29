<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use OpenTelemetry\Contrib\Jaeger\Exporter as JaegerExporter;
use OpenTelemetry\Sdk\Trace\Attributes;
use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\Sdk\Trace\Sampler\ParentBased;
use OpenTelemetry\Sdk\Trace\SamplingResult;
use OpenTelemetry\Sdk\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Sdk\Trace\TracerProvider;
use OpenTelemetry\Trace as API;

$traceId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$spanId = 'bbbbbbbbbbbbbbbb';

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
    'parent-based',
    API\SpanKind::KIND_SERVER
);

$span = $tracer->startAndActivateSpanFromContext('parent-based', $parentContext, true) ;
$tracer->endActiveSpan();

