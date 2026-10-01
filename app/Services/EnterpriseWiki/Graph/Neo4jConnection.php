<?php

namespace App\Services\EnterpriseWiki\Graph;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\AuthenticateInterface;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SessionConfiguration;

/**
 * How Procynia connects to Neo4j, in one place.
 *
 * The write side (GraphProjection) and the read side (GraphQuery) must reach the same database
 * with the same credentials and the same session configuration. Keeping two copies of the
 * builder would mean a future change — TLS, a timeout, a routing context — silently applying to
 * only one half of the pilot.
 *
 * The client is built lazily: nothing here opens a socket for a request that never touches the
 * graph, and the null implementations are chosen before this class is ever constructed.
 */
class Neo4jConnection
{
    private ?ClientInterface $client = null;

    public function __construct(
        private readonly string $uri,
        private readonly ?string $database,
        private readonly ?string $username,
        private readonly ?string $password,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client;
    }

    public function client(): ClientInterface
    {
        if ($this->client instanceof ClientInterface) {
            return $this->client;
        }

        $builder = ClientBuilder::create()
            ->withDriver('default', $this->uri, $this->authentication())
            ->withDefaultDriver('default');

        if ($this->database !== null && $this->database !== '') {
            $builder = $builder->withDefaultSessionConfiguration(SessionConfiguration::default()->withDatabase($this->database));
        }

        return $this->client = $builder->build();
    }

    private function authentication(): AuthenticateInterface
    {
        if ($this->username === null || $this->username === '') {
            return Authenticate::disabled();
        }

        return Authenticate::basic($this->username, (string) $this->password);
    }
}
