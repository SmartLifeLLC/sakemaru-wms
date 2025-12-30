{!! '<'.'?xml version="1.0"  encoding="utf-8" ?>' !!}
<soap:Envelope
        xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <soap:Header>
        <MessageHeader xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <From>{{$from}}</From>
            <To>{{$to}}</To>
            <MessageId>{{$message_id}}</MessageId>
            <Timestamp>{{$timestamp}}</Timestamp>
        </MessageHeader>
    </soap:Header>
    <soap:Body>
        <PutDocumentResponse xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <PutDocumentResult>{{$result}}</PutDocumentResult>
        </PutDocumentResponse>
    </soap:Body>
</soap:Envelope>
