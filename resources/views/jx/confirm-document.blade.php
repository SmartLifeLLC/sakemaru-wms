{!! '<'.'?xml version="1.0"  encoding="utf-8" ?>' !!}
<Soap:Envelope xmlns:Soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <Soap:Header>
        <MessageHeader xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <From>{{$from}}</From>
            <To>{{$to}}</To>
            <MessageId>{{$message_id}}</MessageId>
            <Timestamp>{{$timestamp}}</Timestamp>
        </MessageHeader>
    </Soap:Header>
    <Soap:Body>
        <ConfirmDocument xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <messageId>{{$received_message_id}}</messageId>
            <senderId>{{$sender_id}}</senderId>
            <receiverId>{{$receiver_id}}</receiverId>
        </ConfirmDocument>
    </Soap:Body>
</Soap:Envelope>
