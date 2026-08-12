<?php

use App\Mcp\Servers\SorifyServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/sorify/mcp', SorifyServer::class)->middleware('sorify.mcp.auth');
