<?php

namespace OxygenSuite\AadeMyData\Response;

use Firebed\AadeMyData\Models\ResponseDoc;
use Firebed\AadeMyData\Xml\XMLWriter;
use RuntimeException;

/**
 * Serializes a ResponseDoc into the XML AADE returns, so the package's
 * ResponseDocReader parses it unchanged. One instance per document.
 *
 * @extends XMLWriter<ResponseDoc>
 */
final class ResponseDocWriter extends XMLWriter
{
    /** @var array<string, string> */
    protected array $namespaces = [];

    /**
     * @param ResponseDoc $data
     *
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function asXml($data): string
    {
        $root = $this->document->createElement('ResponseDoc');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $this->document->appendChild($root);

        $this->buildArray($root, 'response', $data->all());

        $xml = $this->document->saveXML();

        return $xml === false ? throw new RuntimeException('The response document could not be serialized to XML.') : $xml;
    }
}
