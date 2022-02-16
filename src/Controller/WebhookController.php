<?php

declare(strict_types=1);

namespace Buddy\Repman\Controller;

use Buddy\Repman\Message\Organization\SynchronizePackage;
use Buddy\Repman\Query\User\Model\Package;
use Buddy\Repman\Query\User\Model\Organization;

use Buddy\Repman\Query\User\PackageQuery;
use Buddy\Repman\Query\User\OrganizationQuery;

use Buddy\Repman\Service\Organization\WebhookRequests;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class WebhookController extends AbstractController
{
    private WebhookRequests $webhookRequests;
    private OrganizationQuery $organizationQuery;
    private PackageQuery $packageQuery;

    public function __construct(WebhookRequests $webhookRequests, OrganizationQuery $organizationQuery, PackageQuery $packageQuery)
    {
        $this->webhookRequests = $webhookRequests;
        $this->organizationQuery = $organizationQuery;
        $this->packageQuery = $packageQuery;
    }

    /**
     * @Route("/hook/organization/{organization}/{token}", name="organization_webhook", methods={"GET","POST"}, requirements={"organization"="%organization_pattern%"})
     */
    public function organizationWebhook(Organization $organization, string $token, Request $request): Response
    {
        $status = ['status' => false, 'message' => 'nothing done'];
        $validToken = $this->organizationQuery->findToken($organization->id(), $token)
            ->getOrNull();
        if ($validToken)
        {
            if ($request->isMethod(Request::METHOD_POST))
            {
                $input = json_decode($request->getContent(), true);

                // do matching here depending on host that posts?
                if (isset($input['repository']['clone_url']))
                {
                    $packageNames = $this->packageQuery->getAllNames($organization->id());
                    $package = null;
                    foreach($packageNames as $searchPackage)
                    {
                        $url = $this->packageQuery->getById($searchPackage->id())->get()->url();
                        if ($url == $input['repository']['clone_url'])
                        {
                            $package = $searchPackage;
                            break;
                        }
                    }
                    if ($package)
                    {
                        $message = sprintf('Package "%s" will be synchronized in background.'."\n", $package->name());
                        $this->dispatchMessage(new SynchronizePackage($package->id()));
                        $status = ['status' => true, 'message' => $message];
                    }
                }
                else
                {
                    $packageNames = $this->packageQuery->getAllNames($organization->id());
                    $message = '';
                    $package = null;
                    foreach($packageNames as $package)
                    {
                        $message .= sprintf('Package "%s" will be synchronized in background.'."\n", $package->name());
                        $this->dispatchMessage(new SynchronizePackage($package->id()));
                    }
                    $status = ['status' => true, 'message' => $message];
                }
            }
        }
        else
            $status = ['status' => false, 'message' => sprintf('Invalid token for %s.', $organization->name())];

        if ($status['status'] === false)
            throw $this->createNotFoundException($status['message']);
        return new JsonResponse($status);
    }

    private MessageBusInterface $messageBus;

    /**
     * @Route("/hook/{package}", name="package_webhook", methods={"POST"})
     */
    public function package(Package $package, Request $request): Response
    {
        $this->messageBus->dispatch(new SynchronizePackage($package->id()));
        $this->webhookRequests->add($package->id(), new \DateTimeImmutable(), $request->getClientIp(), $request->headers->get('User-Agent'));

        return new JsonResponse(null, Response::HTTP_ACCEPTED);
    }
}
