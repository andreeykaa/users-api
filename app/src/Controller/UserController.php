<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/v1/api/users')]
class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository         $userRepository,
        private readonly ValidatorInterface     $validator,
    ) {}

    // Get one user by id.
    // Regular users can read only their own profile.
    // Root users can read any user.
    #[Route('', name: 'api_users_get', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        $id = $request->query->get('id');

        if (!$id) {
            return $this->json(['error' => 'Parameter id is required'], 400);
        }

        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        // Check access rights for regular users
        if (!$this->isGranted('ROLE_ROOT')) {
            $currentUser = $this->getAuthenticatedUser();

            if (!$currentUser) {
                return $this->json(['error' => 'Authentication required'], 401);
            }

            if ($currentUser->getId() !== $user->getId()) {
                return $this->json(['error' => 'Access denied'], 403);
            }
        }

        return $this->json([
            'login' => $user->getLogin(),
            'phone' => $user->getPhone(),
            'pass'  => $user->getPass(),
        ]);
    }

    // Create a new user.
    // Only root users are allowed to create users.
    #[Route('', name: 'api_users_post', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // POST is allowed only for root users
        if (!$this->isGranted('ROLE_ROOT')) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $data = $this->decodeJsonBody($request);

        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        // Fill the User entity with data from the request
        $user = new User();
        $user->setLogin($data['login'] ?? '');
        $user->setPhone($data['phone'] ?? '');
        $user->setPass($data['pass'] ?? '');

        // Validate the user before saving it
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            return $this->json(['error' => $this->formatErrors($errors)], 422);
        }

        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            return $this->json(['error' => 'User with this login+pass already exists'], 409);
        }

        return $this->json([
            'id'    => $user->getId(),
            'login' => $user->getLogin(),
            'phone' => $user->getPhone(),
            'pass'  => $user->getPass(),
        ], 201);
    }

    // Update an existing user.
    // PUT requires id, login, phone and pass.
    #[Route('', name: 'api_users_put', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data = $this->decodeJsonBody($request);

        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        // PUT must receive all required fields
        $missingFields = $this->getMissingFields($data);

        if (count($missingFields) > 0) {
            return $this->json([
                'error' => 'Missing required fields',
                'fields' => $missingFields,
            ], 400);
        }

        $id = $data['id'];

        if (!$id) {
            return $this->json(['error' => 'Field id is required'], 400);
        }

        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        // Check access rights for regular users
        if (!$this->isGranted('ROLE_ROOT')) {
            $currentUser = $this->getAuthenticatedUser();

            if (!$currentUser) {
                return $this->json(['error' => 'Authentication required'], 401);
            }

            if ($currentUser->getId() !== $user->getId()) {
                return $this->json(['error' => 'Access denied'], 403);
            }
        }

        // Replace all user fields from the PUT request
        $user->setLogin($data['login']);
        $user->setPhone($data['phone']);
        $user->setPass($data['pass']);

        // Validate updated data before saving
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            return $this->json(['error' => $this->formatErrors($errors)], 422);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            return $this->json(['error' => 'User with this login+pass already exists'], 409);
        }

        return $this->json(['id' => $user->getId()]);
    }

    // Delete an existing user.
    // Only root users are allowed to delete users.
    #[Route('', name: 'api_users_delete', methods: ['DELETE'])]
    public function delete(Request $request): JsonResponse
    {
        $data = $this->decodeJsonBody($request);

        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $id = $data['id'] ?? null;

        if (!$id) {
            return $this->json(['error' => 'Field id is required'], 400);
        }

        // Only root users can delete users
        if (!$this->isGranted('ROLE_ROOT')) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $user = $this->userRepository->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $this->em->remove($user);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // Decode JSON request body.
    // Returns null if the body is not valid JSON.
    private function decodeJsonBody(Request $request): ?array
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    // Check which required fields are missing from the request body.
    private function getMissingFields(array $data): array
    {
        $fields = ['id', 'login', 'phone', 'pass'];
        $missingFields = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                $missingFields[] = $field;
            }
        }

        return $missingFields;
    }

    // Get the currently authenticated user.
    // Returns null if there is no valid logged-in user.
    private function getAuthenticatedUser(): ?User
    {
        $currentUser = $this->getUser();

        if (!$currentUser instanceof User) {
            return null;
        }

        return $currentUser;
    }

    // Convert validation errors into a simple array.
    // Example: ['login' => 'This value is too long.']
    private function formatErrors(ConstraintViolationListInterface $errors): array
    {
        $result = [];

        foreach ($errors as $error) {
            $result[$error->getPropertyPath()] = $error->getMessage();
        }

        return $result;
    }
}
