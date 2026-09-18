<?php

namespace Omeka\Api;

class Response
{
    protected $content;
    protected $totalResults = 0;
    protected $request;

    public function __construct($content = null)
    {
        $this->content = $content;
    }

    public function setContent($value)
    {
        $this->content = $value;
        return $this;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function setTotalResults($totalResults)
    {
        $this->totalResults = $totalResults;
        return $this;
    }

    public function getTotalResults()
    {
        return $this->totalResults;
    }

    public function setRequest(Request $request)
    {
        $this->request = $request;
        return $this;
    }

    public function getRequest()
    {
        return $this->request;
    }
}
