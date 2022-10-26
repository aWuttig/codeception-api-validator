<?php

namespace Awuttig\Codeception;

/*
 * This file is part of the Codeception ApiValidator Module project
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read
 * LICENSE file that was distributed with this source code.
 *
 */

use Codeception\Lib\InnerBrowser;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Module;
use Codeception\Module\REST;
use Codeception\TestInterface;
use ElevenLabs\Api\Decoder\Adapter\SymfonyDecoderAdapter;
use ElevenLabs\Api\Decoder\DecoderInterface;
use ElevenLabs\Api\Factory\SwaggerSchemaFactory;
use ElevenLabs\Api\Schema;
use ElevenLabs\Api\Validator\MessageValidator;
use Exception;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\Serializer\Encoder\ChainDecoder;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

class ApiValidator extends Module implements DependsOnModule
{

    protected $config = [
        'schema' => ''
    ];

    protected $dependencyMessage = <<<EOF
Example configuring REST as backend for ApiValidator module.
--
modules:
    enabled:
        - Awuttig\Codeception\ApiValidator:
            depends: [REST, PhpBrowser]
            schema: '../../web/api/documentation/swagger.yaml'
--
EOF;

    public $isFunctional = false;

    /**
     * @var InnerBrowser
     */
    protected $connectionModule;

    /**
     * @var REST
     */
    public $rest;

    /**
     * @var MessageValidator
     */
    protected $swaggerMessageValidator;

    /**
     * @var Schema
     */
    protected $swaggerSchema;

    /**
     * @var array
     */
    private $params;

    /**
     * @var string
     */
    private $response;

    /**
     * @var AbstractBrowser
     */
    private $client;

    /**
     * @var Validator
     */
    private $jsonSchemaValidator;

    /**
     * @var DecoderInterface
     */
    private $decoder;

    /**
     * @param TestInterface $test
     */
    public function _before(TestInterface $test)
    {
        $this->client = $this->connectionModule->client;
        $this->resetVariables();

        $this->swaggerMessageValidator = new MessageValidator($this->jsonSchemaValidator, $this->decoder);
    }

    protected function resetVariables()
    {
        $this->params = [];
        $this->response = '';
        $this->connectionModule->headers = [];
    }

    /**
     * @return array
     */
    public function _depends()
    {
        return [REST::class => $this->dependencyMessage];
    }

    /**
     * @param REST $rest
     * @param InnerBrowser $connection
     * @throws Exception
     */
    public function _inject(REST $rest, InnerBrowser $connection)
    {
        $this->rest = $rest;
        $this->connectionModule = $connection;

        if ($this->connectionModule instanceof Framework) {
            $this->isFunctional = true;
        }

        $this->jsonSchemaValidator = new Validator();
        $this->decoder = new SymfonyDecoderAdapter(
            new ChainDecoder([
                new JsonDecode(),
                new XmlEncoder()
            ])
        );

        if ($this->config['schema']) {
            $schema = 'file://' . codecept_root_dir($this->config['schema']);
            if (!file_exists($schema)) {
                throw new Exception("{$schema} not found!");
            }
            $this->swaggerSchema = (new SwaggerSchemaFactory())->createSchema($schema);
        }
    }

    /**
     * @param string $schema
     * @throws \RuntimeException
     */
    public function haveOpenAPISchema($schema)
    {
        if (!file_exists($schema)) {
            throw new RuntimeException("{$schema} not found!");
        }
        $this->swaggerSchema = (new SwaggerSchemaFactory())->createSchema($schema);

    }

    /**
     * @param $schema
     * @throws \RuntimeException
     */
    public function haveSwaggerSchema($schema)
    {
        $this->haveOpenAPISchema($schema);
    }

    /**
     *
     */
    public function seeRequestIsValid()
    {
        $request = $this->getPsr7Request();
        $hasViolations = $this->validateRequestAgainstSchema($request);
        Assert::assertFalse($hasViolations);
    }

    /**
     *
     */
    public function seeResponseIsValid()
    {
        $request = $this->getPsr7Request();
        $response = $this->getPsr7Response();
        $hasViolations = $this->validateResponseAgainstSchema($request, $response);
        Assert::assertFalse($hasViolations);
    }

    /**
     *
     */
    public function seeRequestAndResponseAreValid()
    {
        $this->seeRequestIsValid();
        $this->seeResponseIsValid();
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @return bool
     */
    public function validateRequestAgainstSchema(RequestInterface $request)
    {
        $uri = parse_url($request->getUri())['path'];
        $uri = $path = '/' . ltrim($uri, '/');

        $requestDefinition = $this->swaggerSchema->getRequestDefinition(
            $this->swaggerSchema->findOperationId($request->getMethod(), $uri)
        );

        $this->swaggerMessageValidator->validateRequest($request, $requestDefinition);
        if ($this->swaggerMessageValidator->hasViolations()) {
            codecept_debug($this->swaggerMessageValidator->getViolations());
        }
        return $this->swaggerMessageValidator->hasViolations();
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return bool
     */
    public function validateResponseAgainstSchema(RequestInterface $request, ResponseInterface $response)
    {
        $uri = parse_url($request->getUri())['path'];
        $uri = $path = '/' . ltrim($uri, '/');

        $requestDefinition = $this->swaggerSchema->getRequestDefinition(
            $this->swaggerSchema->findOperationId($request->getMethod(), $uri)
        );

        $headers = $response->getHeaders();
        $headers['Content-Type'] = str_replace('; charset=utf-8', '', $headers['Content-Type']);
        $response = new Response(
            $response->getStatusCode(),
            $headers,
            $response->getBody()->__toString()
        );

        $this->swaggerMessageValidator->validateResponse($response, $requestDefinition);
        if ($this->swaggerMessageValidator->hasViolations()) {
            codecept_debug($this->swaggerMessageValidator->getViolations());
        }
        return $this->swaggerMessageValidator->hasViolations();
    }

    /**
     * @return \Psr\Http\Message\RequestInterface
     *
     * @throws \RuntimeException
     */
    public function getPsr7Request()
    {
        $internalRequest = $this->rest->client->getInternalRequest();
        $headers = $this->connectionModule->headers;

        if (!$internalRequest) {
            throw new RuntimeException('internal request not defined.');
        }

        return new Request(
            $internalRequest->getMethod(),
            $internalRequest->getUri(),
            $headers,
            $internalRequest->getContent()
        );
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws \RuntimeException
     */
    public function getPsr7Response()
    {
        $internalResponse = $this->rest->client->getInternalResponse();

        if (!$internalResponse) {
            throw new RuntimeException('internal request not defined.');
        }

        return new Response(
            $internalResponse->getStatus(),
            $internalResponse->getHeaders(),
            $internalResponse->getContent()
        );
    }
}
