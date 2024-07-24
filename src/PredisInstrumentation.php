<?php

/**
 * @copyright  Copyright (c) 2024 E-vino Comércio de Vinhos S.A. (https://evino.com.br)
 * @author     Kevin Mian Kraiker <kevin.kraiker@evino.com.br>
 * @Link       https://evino.com.br
 */

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Predis\Pipeline\Pipeline;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Predis\Command\CommandInterface;

use Throwable;

class PredisInstrumentation
{
    public const NAME = 'predis';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.predis');
        $attributeTracker = new RedisAttributeTracker();
        hook(
            \Predis\Client::class,
            '__construct',
            pre: static function (
                \Predis\Client $redis,
                array          $params,
                string         $class,
                string         $function,
                ?string        $filename,
                ?int           $lineno,
            ) use ($instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder(
                    $instrumentation,
                    'Predis::__construct',
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )->setSpanKind(SpanKind::KIND_CLIENT);

                $host = $params[0];

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Predis\Client $redis, array $params, mixed $statement, ?Throwable $exception) use (
                $attributeTracker,
            ) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $attributes = $attributeTracker->trackRedisAttributes($redis);
                $span->setAttributes($attributes);

                self::end($exception);
            },
        );
        hook(
            \Predis\Client::class,
            'executeCommand',
            pre: static function (
                \Predis\Client $redis,
                array          $params,
                string         $class,
                string         $function,
                ?string        $filename,
                ?int           $lineno,
            ) use ($attributeTracker, $instrumentation) {
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = self::makeBuilder(
                    $instrumentation,
                    'Predis::executeCommand',
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )
                    ->setSpanKind(SpanKind::KIND_CLIENT);
                if (is_a($class, \Predis\ClientInterface::class, true)) {
                    /** @var array{0: CommandInterface} $params */
                    $statement = $params[0]->getId();
                    $maskArgs = false;
                    foreach ($params[0]->getArguments() as $arg) {
                        if ($maskArgs) {
                            if (is_array($arg)) {
                                foreach ($arg as $subArg) {
                                    $statement .= ' ?';
                                }

                                continue;
                            }
                            $statement .= ' ?';

                            continue;
                        }
                        $maskArgs = true;
                        if (is_array($arg) && array_is_list($arg)) {
                            foreach ($arg as $subArg) {
                                $statement .= ' ' . $subArg;
                            }

                            continue;
                        }
                        $statement .= ' ' . $arg;
                    }
                    $builder->setAttribute(
                        TraceAttributes::DB_STATEMENT,
                        $statement,
                    );
                }
                $parent = Context::getCurrent();
                $span = $builder->startSpan();

                $attributes = $attributeTracker->trackedAttributesForRedis($redis);
                $span->setAttributes($attributes);

                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function (\Predis\Client $redis, array $params, mixed $statement, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                if (is_a($redis, \Predis\ClientInterface::class)) {
                    /** @var array{0: CommandInterface} $params */
                    $span->updateName('Predis::' . $params[0]->getId());
                }
                self::end($exception);
            },
        );
        hook(
            Pipeline::class,
            'executePipeline',
            pre: static function (
                Pipeline $pipeline,
                array    $params,
                string   $class,
                string   $function,
                ?string  $filename,
                ?int     $lineno,
            ) use ($attributeTracker, $instrumentation) {
                $builder = self::makeBuilder(
                    $instrumentation,
                    'Predis::executePipeline',
                    $function,
                    $class,
                    $filename,
                    $lineno,
                )->setSpanKind(SpanKind::KIND_CLIENT);

                $parent = Context::getCurrent();
                $rootSpan = $builder->startSpan();

                Context::storage()->attach($rootSpan->storeInContext($parent));

                if (is_a($class, Pipeline::class, true)) {
                    if (isset($params[1]) && $params[1] instanceof \SplQueue) {
                        /**
                         * @var \SplQueue<CommandInterface> $commands
                         */
                        $commands = clone $params[1];

                        foreach ($commands as $command) {
                            $commandBuilder = self::makeBuilder(
                                $instrumentation,
                                "Predis::{$command->getId()}",
                                $function,
                                $class,
                                $filename,
                                $lineno
                            )->setSpanKind(SpanKind::KIND_CLIENT);

                            $statement = $command->getId();
                            $maskArgs = false;
                            foreach ($command->getArguments() as $arg) {
                                if ($maskArgs) {
                                    if (is_array($arg)) {
                                        foreach ($arg as $subArg) {
                                            $statement .= ' ?';
                                        }

                                        continue;
                                    }
                                    $statement .= ' ?';

                                    continue;
                                }
                                $maskArgs = true;
                                if (is_array($arg) && array_is_list($arg)) {
                                    foreach ($arg as $subArg) {
                                        $statement .= ' ' . $subArg;
                                    }

                                    continue;
                                }
                                $statement .= ' ' . $arg;
                            }
                            $commandBuilder->setAttribute(
                                TraceAttributes::DB_STATEMENT,
                                $statement,
                            );

                            $parent = Context::getCurrent();
                            $span = $commandBuilder->startSpan();

                            $attributes = $attributeTracker->trackedAttributesForRedis($pipeline->getClient());
                            $span->setAttributes($attributes);

                            $span->end();
                        }
                    }
                }
            },
            post: static function (Pipeline $pipeline, array $params, mixed $returnStatement, ?Throwable $exception) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());
                if ($exception) {
                    $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            },
        );
    }

    private static function makeBuilder(
        CachedInstrumentation $instrumentation,
        string                $name,
        string                $function,
        string                $class,
        ?string               $filename,
        ?int                  $lineno,
    ): SpanBuilderInterface
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
    }

    private static function end(?Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());
        if ($exception) {
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
