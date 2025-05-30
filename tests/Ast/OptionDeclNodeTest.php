<?php

declare(strict_types=1);

namespace Butschster\Tests\Ast;

use Butschster\ProtoParser\Ast\CommentNode;
use Butschster\ProtoParser\Ast\FieldDeclNode;
use Butschster\ProtoParser\Ast\FieldType;
use Butschster\ProtoParser\Ast\MessageDefNode;
use Butschster\ProtoParser\Ast\OneofDeclNode;
use Butschster\ProtoParser\Ast\OneofFieldNode;
use Butschster\ProtoParser\Ast\OptionDeclNode;
use Butschster\ProtoParser\Ast\OptionNode;
use Butschster\ProtoParser\Ast\RpcDeclNode;
use Butschster\ProtoParser\Ast\RpcMessageType;
use Butschster\ProtoParser\Ast\ServiceDefNode;

final class OptionDeclNodeTest extends TestCase
{

    public function testParseUserMessage(): void
    {
        $node = $this->parser->parse(
            <<<'PROTO'
syntax = "proto3";
package example;
message User {
    string id = 1;
    string name = 2 [(validate.rules).string = {
        min_len: 3,
        max_len: 50
    }];
    string email = 3 [(validate.rules).string.email = true];
    oneof option {
        string name = 4;
        SubMessage sub_message = 9;
    }
}
PROTO,
        );

        $this->assertCount(1, $node->topLevelDefs);
        $message = $node->topLevelDefs[0];

        $this->assertInstanceOf(MessageDefNode::class, $message);
        $this->assertSame('User', $message->name);
        $this->assertCount(4, $message->fields);

        // Test id field
        $idField = $message->fields[0];
        $this->assertInstanceOf(FieldDeclNode::class, $idField);
        $this->assertSame('id', $idField->name);
        $this->assertEquals(new FieldType('string'), $idField->type);
        $this->assertSame(1, $idField->number);
        $this->assertEmpty($idField->options);

        // Test name field
        $nameField = $message->fields[1];
        $this->assertInstanceOf(FieldDeclNode::class, $nameField);
        $this->assertSame('name', $nameField->name);
        $this->assertEquals(new FieldType('string'), $nameField->type);
        $this->assertSame(2, $nameField->number);
        $this->assertCount(1, $nameField->options);

        $this->assertEquals(
            new OptionNode(
                'validate.rules.string',
                new OptionDeclNode(
                    null,
                    [],
                    [
                        'min_len' => new OptionNode('min_len', 3),
                        'max_len' => new OptionNode('max_len', 50),
                    ],
                ),
            ),
            $nameField->options['validate.rules.string'],
        );

        // Test email field
        $emailField = $message->fields[2];
        $this->assertInstanceOf(FieldDeclNode::class, $emailField);
        $this->assertSame('email', $emailField->name);
        $this->assertEquals(new FieldType('string'), $emailField->type);
        $this->assertSame(3, $emailField->number);
        $this->assertCount(1, $emailField->options);
        $this->assertEquals(
            new OptionNode(
                'validate.rules.string.email',
                true,
            ),
            $emailField->options['validate.rules.string.email'],
        );

        $oneof = $message->fields[3];
        $this->assertInstanceOf(OneofDeclNode::class, $oneof);
        $this->assertSame('option', $oneof->name);

        $this->assertCount(2, $oneof->fields);

        $field1 = $oneof->fields[0];
        $this->assertInstanceOf(OneofFieldNode::class, $field1);
        $this->assertSame('name', $field1->name);
        $this->assertEquals(new FieldType('string'), $field1->type);
        $this->assertSame(4, $field1->number);

        $field2 = $oneof->fields[1];
        $this->assertInstanceOf(OneofFieldNode::class, $field2);
        $this->assertSame('sub_message', $field2->name);
        $this->assertEquals(new FieldType('SubMessage'), $field2->type);
        $this->assertSame(9, $field2->number);
    }

    public function testParseServiceWithComplexOption1(): void
    {
        $node = $this->parser->parse(
            <<<'PROTO'
            syntax = "proto3";
            package example;

            service UserService {
                option (grpc.gateway.protoc_gen_openapiv2.options.openapiv2_tag) = {
                    description: "User management service"
                    external_docs: {
                        url: "https://example.com/docs"
                        description: "User service documentation"
                    }
                };

                rpc DeleteUser (DeleteUserRequest) returns (google.protobuf.Empty) {
                    option (google.api.http) = {
                        delete: "/v1/users/{user_id}"
                    };
                    option deadline = 10.0;
                }
            }
            PROTO,
        );

        $service = $node->topLevelDefs[0];
        $rpc = $service->methods[0];
        $this->assertInstanceOf(RpcDeclNode::class, $rpc);
        $this->assertSame('DeleteUser', $rpc->name);

        $option = $rpc->options[1];
        $this->assertEquals('deadline', $option->options[0]->name);
        $this->assertSame(10.0, $option->options[0]->value);
        $this->assertCount(0, $option->options[0]->comments);
    }

    public function testParseServiceWithComplexOption(): void
    {
        $node = $this->parser->parse(
            <<<'PROTO'
            syntax = "proto3";
            package example;

            service UserService {
                rpc GetUser (GetUserRequest) returns (User) {
                    // Get user by id
                    option (google.api.http) = {
                        get: "/v1/users/{user_id}"
                        body: "email" // User email
                    };
                }
            }
            PROTO,
        );

        $this->assertSame('proto3', $node->syntax->syntax);
        $this->assertSame('example', $node->package->name);

        $this->assertCount(1, $node->topLevelDefs);
        $service = $node->topLevelDefs[0];

        $this->assertInstanceOf(ServiceDefNode::class, $service);
        $this->assertSame('UserService', $service->name);
        $this->assertCount(1, $service->methods);

        $rpc = $service->methods[0];
        $this->assertInstanceOf(RpcDeclNode::class, $rpc);
        $this->assertSame('GetUser', $rpc->name);
        $this->assertInstanceOf(RpcMessageType::class, $rpc->inputType);
        $this->assertSame('GetUserRequest', $rpc->inputType->name);
        $this->assertFalse($rpc->inputType->isStream);
        $this->assertInstanceOf(RpcMessageType::class, $rpc->outputType);
        $this->assertSame('User', $rpc->outputType->name);
        $this->assertFalse($rpc->outputType->isStream);

        $this->assertCount(1, $rpc->options);
        $option = $rpc->options[0];
        $this->assertSame('google.api.http', $option->name);
        $this->assertCount(1, $option->comments);
        $this->assertEquals(new CommentNode('Get user by id'), $option->comments[0]);

        $this->assertEquals('get', $option->options[0]->name);
        $this->assertSame('/v1/users/{user_id}', $option->options[0]->value);
        $this->assertCount(0, $option->options[0]->comments);

        $this->assertEquals('body', $option->options[1]->name);
        $this->assertSame('email', $option->options[1]->value);
        $this->assertCount(1, $option->options[01]->comments);
        $this->assertEquals(new CommentNode('User email'), $option->options[1]->comments[0]);
    }

    public function testParseOptionWithParentheses(): void
    {
        $node = $this->parser->parse(
            <<<'PROTO'
            syntax = "proto3";

            package example;

            option (google.api.default_host) = "user.example.com";
            PROTO,
        );

        $this->assertSame('proto3', $node->syntax->syntax);
        $this->assertSame('example', $node->package->name);

        $this->assertCount(1, $node->options);
        $option = $node->options[0];

        $this->assertInstanceOf(OptionDeclNode::class, $option);
        $this->assertSame('google.api.default_host', $option->name);
        $this->assertEquals(new OptionNode('google.api.default_host', 'user.example.com'), $option->options[0]);
    }

    public function testParseMultipleOptionsWithParentheses(): void
    {
        $node = $this->parser->parse(
            <<<'PROTO'
            syntax = "proto3";

            package example;

            option (google.api.default_host) = "user.example.com";
            option (google.api.oauth_scopes) = "https://www.googleapis.com/auth/userinfo.email";
            option java_multiple_files = true;
            PROTO,
        );

        $this->assertSame('proto3', $node->syntax->syntax);
        $this->assertSame('example', $node->package->name);

        $this->assertCount(3, $node->options);

        $this->assertInstanceOf(OptionDeclNode::class, $node->options[0]);
        $this->assertSame('google.api.default_host', $node->options[0]->name);
        $this->assertEquals(
            new OptionNode('google.api.default_host', 'user.example.com'),
            $node->options[0]->options[0],
        );

        $this->assertInstanceOf(OptionDeclNode::class, $node->options[1]);
        $this->assertSame('google.api.oauth_scopes', $node->options[1]->name);
        $this->assertEquals(
            new OptionNode('google.api.oauth_scopes', 'https://www.googleapis.com/auth/userinfo.email'),
            $node->options[1]->options[0],
        );

        $this->assertInstanceOf(OptionDeclNode::class, $node->options[2]);
        $this->assertSame('java_multiple_files', $node->options[2]->name);
        $this->assertEquals(new OptionNode('java_multiple_files', true), $node->options[2]->options[0]);
    }
}
