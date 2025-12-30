<?php

namespace Tests\Feature\JX;

use App\Models\WmsOrderJxSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JxServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_jx_server_handles_put_document_request(): void
    {
        $xmlRequest = $this->buildPutDocumentRequest();

        $response = $this->withoutMiddleware()
            ->withHeaders([
                'Content-Type' => 'text/xml',
                'SOAPAction' => 'http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server/PutDocument',
            ])->call('POST', '/jx-server', [], [], [], [], $xmlRequest);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('PutDocumentResponse', $content);
        $this->assertStringContainsString('<PutDocumentResult>true</PutDocumentResult>', $content);
    }

    public function test_jx_server_handles_get_document_request(): void
    {
        $xmlRequest = $this->buildGetDocumentRequest();

        $response = $this->withoutMiddleware()
            ->withHeaders([
                'Content-Type' => 'text/xml',
                'SOAPAction' => 'http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server/GetDocument',
            ])->call('POST', '/jx-server', [], [], [], [], $xmlRequest);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('GetDocumentResponse', $content);
        $this->assertStringContainsString('<GetDocumentResult>true</GetDocumentResult>', $content);
    }

    public function test_jx_server_handles_confirm_document_request(): void
    {
        $xmlRequest = $this->buildConfirmDocumentRequest();

        $response = $this->withoutMiddleware()
            ->withHeaders([
                'Content-Type' => 'text/xml',
                'SOAPAction' => 'http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server/ConfirmDocument',
            ])->call('POST', '/jx-server', [], [], [], [], $xmlRequest);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $content = $response->getContent();
        $this->assertStringContainsString('ConfirmDocumentResponse', $content);
        $this->assertStringContainsString('<ConfirmDocumentResult>true</ConfirmDocumentResult>', $content);
    }

    public function test_jx_server_saves_received_request(): void
    {
        $xmlRequest = $this->buildPutDocumentRequest();

        $this->withoutMiddleware()
            ->withHeaders([
                'Content-Type' => 'text/xml',
                'SOAPAction' => 'http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server/PutDocument',
            ])->call('POST', '/jx-server', [], [], [], [], $xmlRequest);

        // リクエストがローカルストレージに保存されたことを確認
        $files = Storage::disk('local')->allFiles('jx-server/received');
        $this->assertNotEmpty($files);
    }

    protected function buildPutDocumentRequest(): string
    {
        $timestamp = now()->format('Y-m-d\TH:i:s');
        $data = base64_encode('TEST ORDER DATA');

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Soap:Envelope xmlns:Soap="http://schemas.xmlsoap.org/soap/envelope/">
    <Soap:Header>
        <MessageHeader xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <From>test.client.co.jp</From>
            <To>test.server.co.jp</To>
            <MessageId>put_test_123@test.client.co.jp</MessageId>
            <Timestamp>{$timestamp}</Timestamp>
        </MessageHeader>
    </Soap:Header>
    <Soap:Body>
        <PutDocument xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <messageId>put_test_123@test.client.co.jp</messageId>
            <data>{$data}</data>
            <senderId>TEST_CLIENT</senderId>
            <receiverId>TEST_SERVER</receiverId>
            <formatType>SecondGenEDI</formatType>
            <documentType>01</documentType>
            <compressType></compressType>
        </PutDocument>
    </Soap:Body>
</Soap:Envelope>
XML;
    }

    protected function buildGetDocumentRequest(): string
    {
        $timestamp = now()->format('Y-m-d\TH:i:s');

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Header>
        <MessageHeader xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <From>test.client.co.jp</From>
            <To>test.server.co.jp</To>
            <MessageId>get_test_123@test.client.co.jp</MessageId>
            <Timestamp>{$timestamp}</Timestamp>
        </MessageHeader>
    </soap:Header>
    <soap:Body>
        <GetDocument xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <receiverId>TEST_CLIENT</receiverId>
        </GetDocument>
    </soap:Body>
</soap:Envelope>
XML;
    }

    protected function buildConfirmDocumentRequest(): string
    {
        $timestamp = now()->format('Y-m-d\TH:i:s');

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<Soap:Envelope xmlns:Soap="http://schemas.xmlsoap.org/soap/envelope/">
    <Soap:Header>
        <MessageHeader xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <From>test.client.co.jp</From>
            <To>test.server.co.jp</To>
            <MessageId>confirm_test_123@test.client.co.jp</MessageId>
            <Timestamp>{$timestamp}</Timestamp>
        </MessageHeader>
    </Soap:Header>
    <Soap:Body>
        <ConfirmDocument xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <messageId>get_data_test_123@test.server.co.jp</messageId>
            <senderId>TEST_SERVER</senderId>
            <receiverId>TEST_CLIENT</receiverId>
        </ConfirmDocument>
    </Soap:Body>
</Soap:Envelope>
XML;
    }
}
