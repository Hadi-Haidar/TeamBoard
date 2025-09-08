<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Board;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActivityController extends Controller
{
    /**
     * Get activity feed for a specific board
     * 📝 BASIC FUNCTION: Returns activity history for a board
     */
    public function index(Request $request, string $boardId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the board
            $board = Board::findOrFail($boardId);
            
            // Check if user can view this board
            if (!$board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view activities in this board.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Get query parameters for filtering
            $perPage = min($request->get('per_page', 50), 100); // Max 100 per page
            $days = $request->get('days', 30); // Default last 30 days
            $action = $request->get('action'); // Filter by specific action
            $userId = $request->get('user_id'); // Filter by specific user
            $search = $request->get('search'); // Search in descriptions
            
            // Build query
            $query = Activity::with([
                'user:id,name,avatar',
                'subject' // Load the polymorphic subject
            ])
            ->inBoard($board)
            ->recent($days)
            ->chronological();
            
            // Apply filters
            if ($action) {
                $query->withAction($action);
            }
            
            if ($userId) {
                $query->byUser(User::findOrFail($userId));
            }
            
            if ($search) {
                $query->search($search);
            }
            
            // Get paginated results
            $activities = $query->paginate($perPage);
            
            // Transform activities for response
            $transformedActivities = $activities->getCollection()->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'action' => $activity->action,
                    'description' => $activity->description,
                    'formatted_description' => $activity->formatted_description,
                    'created_at' => $activity->created_at,
                    'age' => $activity->age,
                    
                    // User who performed the action
                    'user' => $activity->user ? [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                        'avatar' => $activity->user->avatar,
                    ] : [
                        'id' => null,
                        'name' => 'Unknown User',
                        'avatar' => null,
                    ],
                    
                    // Subject information (what was acted upon)
                    'subject' => $activity->subject ? [
                        'id' => $activity->subject->id,
                        'type' => $activity->subject_type,
                        'title' => $activity->subject->title ?? $activity->subject->name ?? 'Unknown',
                    ] : null,
                    
                    // Display helpers
                    'icon' => $activity->icon,
                    'color' => $activity->color,
                    'summary' => $activity->summary,
                ];
            });
            
            return response()->json([
                'message' => 'Activities retrieved successfully',
                'activities' => $transformedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'last_page' => $activities->lastPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'has_more' => $activities->hasMorePages(),
                ],
                'board_info' => [
                    'id' => $board->id,
                    'title' => $board->title,
                    'activities_count' => $transformedActivities->count(),
                ],
                'filters' => [
                    'days' => $days,
                    'action' => $action,
                    'user_id' => $userId,
                    'search' => $search,
                ],
                'statistics' => Activity::getBoardStats($board, $days),
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Board not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Activities retrieval failed', [
                'board_id' => $boardId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve activities. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific activity with full details
     */
    public function show(Request $request, string $activityId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the activity with all relationships
            $activity = Activity::with([
                'user:id,name,avatar',
                'board:id,title,owner_id',
                'subject'
            ])->findOrFail($activityId);
            
            // Check if user can view this activity
            if (!$activity->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view this activity.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            return response()->json([
                'message' => 'Activity retrieved successfully',
                'activity' => [
                    'id' => $activity->id,
                    'action' => $activity->action,
                    'description' => $activity->description,
                    'formatted_description' => $activity->formatted_description,
                    'created_at' => $activity->created_at,
                    'updated_at' => $activity->updated_at,
                    'age' => $activity->age,
                    
                    // User who performed the action
                    'user' => $activity->user ? [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                        'avatar' => $activity->user->avatar,
                    ] : [
                        'id' => null,
                        'name' => 'Unknown User',
                        'avatar' => null,
                    ],
                    
                    // Board information
                    'board' => [
                        'id' => $activity->board->id,
                        'title' => $activity->board->title,
                    ],
                    
                    // Subject information (what was acted upon)
                    'subject' => $activity->subject ? [
                        'id' => $activity->subject->id,
                        'type' => $activity->subject_type,
                        'title' => $activity->subject->title ?? $activity->subject->name ?? 'Unknown',
                        'data' => $this->getSubjectDetails($activity->subject),
                    ] : null,
                    
                    // Display helpers
                    'icon' => $activity->icon,
                    'color' => $activity->color,
                    'summary' => $activity->summary,
                    
                    // Related users for notifications
                    'related_users' => collect($activity->getRelatedUsers())->map(function($user) {
                        return [
                            'id' => $user['id'],
                            'name' => $user['name'],
                            'avatar' => $user['avatar'],
                        ];
                    }),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Activity not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Activity retrieval failed', [
                'activity_id' => $activityId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve activity. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user's activity feed across all accessible boards
     */
    public function userFeed(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Get query parameters
            $perPage = min($request->get('per_page', 50), 100);
            $days = $request->get('days', 30);
            $action = $request->get('action');
            $boardId = $request->get('board_id');
            
            // Build query for activities in boards user has access to
            $query = Activity::with([
                'user:id,name,avatar',
                'board:id,title',
                'subject'
            ])
            ->accessibleByUser($user)
            ->recent($days)
            ->chronological();
            
            // Apply filters
            if ($action) {
                $query->withAction($action);
            }
            
            if ($boardId) {
                $query->inBoard(Board::findOrFail($boardId));
            }
            
            // Get paginated results
            $activities = $query->paginate($perPage);
            
            // Transform activities for response
            $transformedActivities = $activities->getCollection()->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'action' => $activity->action,
                    'description' => $activity->description,
                    'created_at' => $activity->created_at,
                    'age' => $activity->age,
                    
                    // User who performed the action
                    'user' => $activity->user ? [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                        'avatar' => $activity->user->avatar,
                    ] : null,
                    
                    // Board information
                    'board' => [
                        'id' => $activity->board->id,
                        'title' => $activity->board->title,
                    ],
                    
                    // Subject information
                    'subject' => $activity->subject ? [
                        'id' => $activity->subject->id,
                        'type' => $activity->subject_type,
                        'title' => $activity->subject->title ?? $activity->subject->name ?? 'Unknown',
                    ] : null,
                    
                    // Display helpers
                    'icon' => $activity->icon,
                    'color' => $activity->color,
                    'summary' => $activity->summary,
                ];
            });
            
            return response()->json([
                'message' => 'User activity feed retrieved successfully',
                'activities' => $transformedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'last_page' => $activities->lastPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'has_more' => $activities->hasMorePages(),
                ],
                'user_stats' => Activity::getUserStats($user, $days),
                'filters' => [
                    'days' => $days,
                    'action' => $action,
                    'board_id' => $boardId,
                ],
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('User activity feed retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve activity feed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get activity statistics for a board
     */
    public function boardStats(Request $request, string $boardId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the board
            $board = Board::findOrFail($boardId);
            
            // Check if user can view this board
            if (!$board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view board statistics.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            $days = $request->get('days', 30);
            $stats = Activity::getBoardStats($board, $days);
            
            return response()->json([
                'message' => 'Board statistics retrieved successfully',
                'board' => [
                    'id' => $board->id,
                    'title' => $board->title,
                ],
                'period' => [
                    'days' => $days,
                    'from' => now()->subDays($days)->format('Y-m-d'),
                    'to' => now()->format('Y-m-d'),
                ],
                'statistics' => $stats,
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Board not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Board statistics retrieval failed', [
                'board_id' => $boardId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve board statistics. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user activity statistics
     */
    public function userStats(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $days = $request->get('days', 30);
            $stats = Activity::getUserStats($user, $days);
            
            return response()->json([
                'message' => 'User statistics retrieved successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'period' => [
                    'days' => $days,
                    'from' => now()->subDays($days)->format('Y-m-d'),
                    'to' => now()->format('Y-m-d'),
                ],
                'statistics' => $stats,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('User statistics retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve user statistics. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete an activity (admin only)
     */
    public function destroy(Request $request, string $activityId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the activity with its board
            $activity = Activity::with('board:id,title,owner_id')->findOrFail($activityId);
            
            // Check if user can delete this activity (only board owner)
            if (!$activity->board->isOwnedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to delete this activity.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Store info for response
            $activityDescription = $activity->description;
            
            // Delete the activity
            $activity->delete();
            
            return response()->json([
                'message' => "Activity '{$activityDescription}' has been deleted successfully.",
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Activity not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Activity deletion failed', [
                'activity_id' => $activityId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to delete activity. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get subject details based on type
     */
    private function getSubjectDetails($subject): array
    {
        if (!$subject) {
            return [];
        }

        return match(get_class($subject)) {
            'App\Models\Task' => [
                'title' => $subject->title,
                'status' => $subject->status,
                'priority' => $subject->priority,
                'list_title' => $subject->list?->title,
            ],
            'App\Models\Board' => [
                'title' => $subject->title,
                'description' => $subject->description,
                'owner' => $subject->owner?->name,
            ],
            'App\Models\ListModel' => [
                'title' => $subject->title,
                'color' => $subject->color,
                'tasks_count' => $subject->tasks_count,
            ],
            'App\Models\Comment' => [
                'content' => substr($subject->content, 0, 100) . (strlen($subject->content) > 100 ? '...' : ''),
                'task_title' => $subject->task?->title,
            ],
            default => []
        };
    }
}
