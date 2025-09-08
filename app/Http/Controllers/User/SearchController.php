<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Task;
use App\Models\Comment;
use App\Models\User;
use App\Models\ListModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class SearchController extends Controller
{
    /**
     * Global search across all resources
     * 🔍 MAIN SEARCH: Search everything the user has access to
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Validate search parameters
            $validated = $request->validate([
                'q' => 'required|string|min:2|max:255',
                'type' => 'sometimes|string|in:all,boards,tasks,comments,users',
                'board_id' => 'sometimes|integer|exists:boards,id',
                'limit' => 'sometimes|integer|min:1|max:50',
            ]);
            
            $query = $validated['q'];
            $type = $validated['type'] ?? 'all';
            $boardId = $validated['board_id'] ?? null;
            $limit = $validated['limit'] ?? 20;
            
            $results = [];
            
            // Search based on type
            if ($type === 'all' || $type === 'boards') {
                $results['boards'] = $this->searchBoards($user, $query, $limit);
            }
            
            if ($type === 'all' || $type === 'tasks') {
                $results['tasks'] = $this->searchTasks($user, $query, $boardId, $limit);
            }
            
            if ($type === 'all' || $type === 'comments') {
                $results['comments'] = $this->searchComments($user, $query, $boardId, $limit);
            }
            
            if ($type === 'all' || $type === 'users') {
                $results['users'] = $this->searchUsers($user, $query, $limit);
            }
            
            // Calculate total results
            $totalResults = collect($results)->sum(function ($items) {
                return is_array($items) ? count($items) : 0;
            });
            
            return response()->json([
                'message' => "Found {$totalResults} results for '{$query}'",
                'query' => $query,
                'type' => $type,
                'board_id' => $boardId,
                'total_results' => $totalResults,
                'results' => $results,
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Search failed', [
                'user_id' => $user->id,
                'query' => $request->get('q'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Search failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search boards
     * 🏠 BOARD SEARCH: Find boards by title and description
     */
    public function searchBoards(User $user, string $query, int $limit = 20): array
    {
        $boards = Board::accessibleByUser($user)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            })
            ->with(['owner:id,name,avatar', 'members:id,name,avatar'])
            ->limit($limit)
            ->get();
        
        return $boards->map(function ($board) {
            return [
                'id' => $board->id,
                'title' => $board->title,
                'description' => $board->description,
                'color' => $board->color,
                'is_public' => $board->is_public,
                'created_at' => $board->created_at,
                'owner' => [
                    'id' => $board->owner->id,
                    'name' => $board->owner->name,
                    'avatar' => $board->owner->avatar,
                ],
                'members_count' => $board->members->count(),
                'tasks_count' => $board->tasks_count,
                'completed_tasks_count' => $board->completed_tasks_count,
                'progress_percentage' => $board->progress_percentage,
                'type' => 'board',
                'url' => "/boards/{$board->id}",
            ];
        })->toArray();
    }

    /**
     * Search tasks
     * ✅ TASK SEARCH: Find tasks by title, description, and tags
     */
    public function searchTasks(User $user, string $query, ?int $boardId = null, int $limit = 20): array
    {
        $tasksQuery = Task::whereHas('list.board', function ($q) use ($user) {
                $q->accessibleByUser($user);
            })
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%")
                  ->orWhereJsonContains('tags', $query);
            })
            ->with([
                'list:id,title,board_id', 
                'list.board:id,title,color',
                'assignedUser:id,name,avatar',
                'creator:id,name,avatar'
            ]);
        
        // Filter by specific board if provided
        if ($boardId) {
            $tasksQuery->whereHas('list', function ($q) use ($boardId) {
                $q->where('board_id', $boardId);
            });
        }
        
        $tasks = $tasksQuery->limit($limit)->get();
        
        return $tasks->map(function ($task) {
            return [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date,
                'tags' => $task->tags,
                'created_at' => $task->created_at,
                'list' => [
                    'id' => $task->list->id,
                    'title' => $task->list->title,
                ],
                'board' => [
                    'id' => $task->list->board->id,
                    'title' => $task->list->board->title,
                    'color' => $task->list->board->color,
                ],
                'assigned_user' => $task->assignedUser ? [
                    'id' => $task->assignedUser->id,
                    'name' => $task->assignedUser->name,
                    'avatar' => $task->assignedUser->avatar,
                ] : null,
                'creator' => [
                    'id' => $task->creator->id,
                    'name' => $task->creator->name,
                    'avatar' => $task->creator->avatar,
                ],
                'is_overdue' => $task->isOverdue(),
                'is_due_soon' => $task->isDueSoon(),
                'is_completed' => $task->isCompleted(),
                'comments_count' => $task->comments_count,
                'attachments_count' => $task->attachments_count,
                'type' => 'task',
                'url' => "/boards/{$task->list->board_id}/tasks/{$task->id}",
            ];
        })->toArray();
    }

    /**
     * Search comments
     * 💬 COMMENT SEARCH: Find comments by content
     */
    public function searchComments(User $user, string $query, ?int $boardId = null, int $limit = 20): array
    {
        $commentsQuery = Comment::whereHas('task.list.board', function ($q) use ($user) {
                $q->accessibleByUser($user);
            })
            ->where('content', 'like', "%{$query}%")
            ->with([
                'task:id,title,list_id',
                'task.list:id,title,board_id',
                'task.list.board:id,title,color',
                'user:id,name,avatar'
            ]);
        
        // Filter by specific board if provided
        if ($boardId) {
            $commentsQuery->whereHas('task.list', function ($q) use ($boardId) {
                $q->where('board_id', $boardId);
            });
        }
        
        $comments = $commentsQuery->limit($limit)->get();
        
        return $comments->map(function ($comment) {
            return [
                'id' => $comment->id,
                'content' => $comment->content,
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
                'task' => [
                    'id' => $comment->task->id,
                    'title' => $comment->task->title,
                ],
                'board' => [
                    'id' => $comment->task->list->board->id,
                    'title' => $comment->task->list->board->title,
                    'color' => $comment->task->list->board->color,
                ],
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'avatar' => $comment->user->avatar,
                ],
                'type' => 'comment',
                'url' => "/boards/{$comment->task->list->board_id}/tasks/{$comment->task_id}#comment-{$comment->id}",
            ];
        })->toArray();
    }

    /**
     * Search users
     * 👥 USER SEARCH: Find users by name and email (for mentions/assignments)
     */
    public function searchUsers(User $currentUser, string $query, int $limit = 20): array
    {
        // Only search users who are members of boards the current user has access to
        $users = User::whereHas('boardMemberships.board', function ($q) use ($currentUser) {
                $q->accessibleByUser($currentUser);
            })
            ->where('id', '!=', $currentUser->id) // Exclude current user
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit($limit)
            ->get();
        
        return $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'created_at' => $user->created_at,
                'type' => 'user',
                'url' => "/users/{$user->id}",
            ];
        })->toArray();
    }

    /**
     * Quick search (for autocomplete/suggestions)
     * ⚡ QUICK SEARCH: Fast search for autocomplete
     */
    public function quickSearch(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $validated = $request->validate([
                'q' => 'required|string|min:1|max:100',
                'type' => 'sometimes|string|in:boards,tasks,users',
            ]);
            
            $query = $validated['q'];
            $type = $validated['type'] ?? 'boards';
            $limit = 5; // Quick search returns fewer results
            
            $results = match($type) {
                'boards' => $this->searchBoards($user, $query, $limit),
                'tasks' => $this->searchTasks($user, $query, null, $limit),
                'users' => $this->searchUsers($user, $query, $limit),
                default => []
            };
            
            return response()->json([
                'query' => $query,
                'type' => $type,
                'results' => $results,
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Quick search failed', [
                'user_id' => $user->id,
                'query' => $request->get('q'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Quick search failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search within a specific board
     * 🏠 BOARD-SPECIFIC SEARCH: Search everything within one board
     */
    public function searchInBoard(Request $request, string $boardId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Validate board access
            $board = Board::findOrFail($boardId);
            
            if (!$board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to search this board.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            $validated = $request->validate([
                'q' => 'required|string|min:2|max:255',
                'type' => 'sometimes|string|in:all,tasks,comments,lists',
                'limit' => 'sometimes|integer|min:1|max:50',
            ]);
            
            $query = $validated['q'];
            $type = $validated['type'] ?? 'all';
            $limit = $validated['limit'] ?? 20;
            
            $results = [];
            
            // Search within the specific board
            if ($type === 'all' || $type === 'tasks') {
                $results['tasks'] = $this->searchTasks($user, $query, (int)$boardId, $limit);
            }
            
            if ($type === 'all' || $type === 'comments') {
                $results['comments'] = $this->searchComments($user, $query, (int)$boardId, $limit);
            }
            
            if ($type === 'all' || $type === 'lists') {
                $results['lists'] = $this->searchLists($user, $query, (int)$boardId, $limit);
            }
            
            $totalResults = collect($results)->sum(fn($items) => count($items));
            
            return response()->json([
                'message' => "Found {$totalResults} results for '{$query}' in board '{$board->title}'",
                'query' => $query,
                'type' => $type,
                'board' => [
                    'id' => $board->id,
                    'title' => $board->title,
                    'color' => $board->color,
                ],
                'total_results' => $totalResults,
                'results' => $results,
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Board search failed', [
                'user_id' => $user->id,
                'board_id' => $boardId,
                'query' => $request->get('q'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Board search failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search lists within a board
     */
    private function searchLists(User $user, string $query, int $boardId, int $limit = 20): array
    {
        $lists = ListModel::where('board_id', $boardId)
            ->whereHas('board', function ($q) use ($user) {
                $q->accessibleByUser($user);
            })
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            })
            ->with('board:id,title,color')
            ->limit($limit)
            ->get();
        
        return $lists->map(function ($list) {
            return [
                'id' => $list->id,
                'title' => $list->title,
                'description' => $list->description,
                'position' => $list->position,
                'created_at' => $list->created_at,
                'board' => [
                    'id' => $list->board->id,
                    'title' => $list->board->title,
                    'color' => $list->board->color,
                ],
                'tasks_count' => $list->tasks_count,
                'completed_tasks_count' => $list->completed_tasks_count,
                'type' => 'list',
                'url' => "/boards/{$list->board_id}#list-{$list->id}",
            ];
        })->toArray();
    }

    /**
     * Get search suggestions
     * 💡 SUGGESTIONS: Popular searches and recent searches
     */
    public function suggestions(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Get user's recent boards for suggestions
            $recentBoards = Board::accessibleByUser($user)
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get(['id', 'title']);
            
            // Get user's recent tasks for suggestions
            $recentTasks = Task::whereHas('list.board', function ($q) use ($user) {
                    $q->accessibleByUser($user);
                })
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get(['id', 'title']);
            
            return response()->json([
                'suggestions' => [
                    'recent_boards' => $recentBoards->map(fn($board) => [
                        'id' => $board->id,
                        'title' => $board->title,
                        'type' => 'board',
                    ]),
                    'recent_tasks' => $recentTasks->map(fn($task) => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'type' => 'task',
                    ]),
                    'popular_searches' => [
                        'overdue tasks',
                        'high priority',
                        'assigned to me',
                        'due today',
                        'completed',
                    ],
                ],
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Search suggestions failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to get search suggestions.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
