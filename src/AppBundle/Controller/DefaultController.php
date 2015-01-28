<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use AppBundle\Psr\Response;
use Psr\Http\Message\ServerRequestInterface;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     * @param Psr\Http\Message\ServerRequestInterface $request
     * @return Psr\Http\Message\ResponseInterface
     */
    public function indexAction(ServerRequestInterface $request)
    {
        $body     = sprintf('Howdy, we received an %s from %s', $request->getMethod(), $request->getHeader('User-Agent'));
        $response = new Response();
        $response = $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain');
        $response->getBody()->write($body);

        return $response;
    }
}
