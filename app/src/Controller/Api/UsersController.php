<?php

namespace App\Controller\Api;

use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Draw\Component\OpenApi\Request\ValueResolver\RequestBody;
use Draw\Component\OpenApi\Schema as OpenApi;
use Draw\Component\OpenApi\Serializer\Serialization;
use Draw\DoctrineExtra\ORM\EntityHandler;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @method user getUser()
 */
class UsersController extends AbstractController
{
    /**
     * @return User The newly created user
     */
    #[Route(path: '/users', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OpenApi\Operation(operationId: 'userCreate')]
    public function createAction(
        #[RequestBody] User $target,
        EntityManagerInterface $entityManager
    ): User {
        $entityManager->persist($target);
        $entityManager->flush();

        return $target;
    }

    /**
     * @return User The currently connected user
     */
    #[Route(path: '/me', name: 'me', methods: ['GET'])]
    #[OpenApi\Operation(operationId: 'me')]
    public function meAction(): User
    {
        return $this->getUser();
    }

    /**
     * @return User The update user
     */
    #[Route(path: '/users/{id}', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OpenApi\Operation(operationId: 'userEdit')]
    #[OpenApi\PathParameter(name: 'id', description: 'The user id to edit', type: 'integer')]
    public function editAction(
        #[RequestBody(propertiesMap: ['id' => 'id'])] User $target,
        EntityManagerInterface $entityManager
    ): User {
        $entityManager->flush();

        return $target;
    }

    /**
     * @return array<Tag> The new list of tags
     */
    #[Route(path: '/users/{id}/tags', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OpenApi\Operation(operationId: 'userSetTags')]
    #[Entity('target', class: User::class)]
    public function setTagsAction(
        User $target,
        #[RequestBody(type: 'array<App\Entity\Tag>')] array $tags,
        EntityManagerInterface $entityManager
    ): array {
        $target->setTags($tags);

        $entityManager->flush();

        return $target->getTags()->toArray();
    }

    /**
     * @return User The user
     */
    #[Route(path: '/users/{id}', name: 'user_get', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[Entity('target', class: User::class)]
    #[OpenApi\Operation(operationId: 'userGet')]
    public function getAction(User $target): User
    {
        return $target;
    }

    /**
     * @return void Empty response mean success
     */
    #[Route(path: '/users/{id}', name: 'user_delete', methods: ['DELETE'])]
    #[Entity('target', class: User::class)]
    #[IsGranted('ROLE_ADMIN')]
    #[OpenApi\Operation(operationId: 'userDelete')]
    #[Serialization(statusCode: 204)]
    public function deleteAction(User $target, EntityManagerInterface $entityManager): void
    {
        $entityManager->remove($target);
        $entityManager->flush();
    }

    /**
     * Return a paginator list of users.
     *
     * @return User[] All users
     */
    #[Route(path: '/users', methods: ['GET'])]
    #[OpenApi\Operation(operationId: 'userList')]
    public function listAction(EntityHandler $entityHandler): array
    {
        return $entityHandler->findAll(User::class);
    }

    /**
     * Send a reset password email to the user.
     *
     * @return void No return value mean email has been sent
     */
    #[Route(path: '/users/{id}/reset-password-email', methods: ['POST'])]
    #[OpenApi\Operation(operationId: 'userSendResetPasswordEmail')]
    public function sendResetPasswordEmail(User $target): void
    {
    }
}
