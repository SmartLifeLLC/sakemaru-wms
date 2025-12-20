{!! '<'.'?xml version="1.0"  encoding="utf-8" ?>' !!}
<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">
    <S:Header>
        <MessageHeader xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <From>{{$from}}</From>
            <To>{{$to}}</To>
            <MessageId>{{$message_id}}</MessageId>
            <Timestamp>{{$timestamp}}</Timestamp>
        </MessageHeader>
    </S:Header>
    <S:Body>
        <GetDocumentResponse xmlns="http://www.dsri.jp/edi-bp/2004/jedicos-xml/client-server">
            <GetDocumentResult>{{$result}}</GetDocumentResult>
            <messageId>{{$document_message_id}}</messageId>
            <data>{{$data}}</data>
            <senderId>{{$sender_id}}</senderId>
            <receiverId>{{$receiver_id}}</receiverId>
            <formatType>{{$format_type}}</formatType>
            <documentType>{{$document_type}}</documentType>
            <compressType/>
        </GetDocumentResponse>
    </S:Body>
</S:Envelope>
