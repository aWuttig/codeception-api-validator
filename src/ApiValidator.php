<?php

namespace Codeception\Module;

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

use Codeception\Exception\ModuleException;
use Codeception\Lib\Framework;
use Codeception\Lib\InnerBrowser;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Module;
use Codeception\TestInterface;
use Codeception\Util\JsonArray;
use ElevenLabs\Api\Factory\SwaggerSchemaFactory;
use ElevenLabs\Api\Schema;
use ElevenLabs\Api\Validator\MessageValidator;
use PHPUnit\Framework\Assert;

/**
 * Class ApiValidator
 * @package Codeception\Module
 */
class ApiValidator extends Module implements DependsOnModule
{

    protected $config = [];

    protected $dependencyMessage = <<<EOF
Example configuring REST as backend for ApiValidator module.
--
modules:
    enabled:
        - ApiValidator:
            depends: REST
--
EOF;

    public $isFunctional = false;

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
     * @param TestInterface $test
     */
    public function _before(TestInterface $test)
    {
        $this->client = &$this->connectionModule->client;
        $this->resetVariables();
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
     */
    public function _inject(REST $rest)
    {
        $this->rest = $rest;

        $jsonSchemaValidator = new Validator();
        $decoder = new SymfonyDecoderAdapter(
            new ChainDecoder([
                new JsonDecode(),
                new XmlEncoder()
            ])
        );
        $this->swaggerMessageValidator = new MessageValidator($jsonSchemaValidator, $decoder);
        $this->swaggerSchema = (new SwaggerSchemaFactory())->createSchema('file://' . \codecept_root_dir('../../web/api/documentation/swagger.yaml'));
    }

}
