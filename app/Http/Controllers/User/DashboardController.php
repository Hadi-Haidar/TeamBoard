<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Task;
use App\Models\Activity;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get user dashboard overview
     * 📊 MAIN DASHBOARD: User's complete overview
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Get dashboard data
            $stats = $this->getUserStats($user);
            $recentBoards = $this->getRecentBoards($user);
            $myTasks = $this->getMyTasks($user);
            $recentActivity = $this->getRecentActivity($user);
            $notifications = $this->getNotificationSummary($user);
            
            return response()->json([
                'message' => "Welcome back, {$user->name}!",
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'created_at' => $user->created_at,
                ],
                'statistics' => $stats,
                'recent_boards' => $recentBoards,
                'my_tasks' => $myTasks,
                'recent_activity' => $recentActivity,
                'notifications' => $notifications,
                'quick_actions' => [
                    'create_board' => '/boards',
                    'create_task' => '/tasks',
                    'view_calendar' => '/calendar',
                    'search' => '/search',
                ],
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Dashboard retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to load dashboard. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get detailed user statistics
     * 📈 USER STATS: Comprehensive user metrics
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $validated = $request->validate([
                'period' => 'sometimes|string|in:week,month,quarter,year',
                'board_id' => 'sometimes|integer|exists:boards,id',
            ]);
            
            $period = $validated['period'] ?? 'month';
            $boardId = $validated['board_id'] ?? null;
            
            // Get date range based on period
            $dateRange = $this->getDateRange($period);
            
            // Get comprehensive stats
            $stats = [
                'overview' => $this->getUserStats($user, $boardId),
                'productivity' => $this->getProductivityStats($user, $dateRange, $boardId),
                'collaboration' => $this->getCollaborationStats($user, $dateRange, $boardId),
                'trends' => $this->getTrendStats($user, $dateRange, $boardId),
            ];
            
            return response()->json([
                'message' => 'User statistics retrieved successfully',
                'period' => $period,
                'date_range' => [
                    'start' => $dateRange['start']->toDateString(),
                    'end' => $dateRange['end']->toDateString(),
                ],
                'board_id' => $boardId,
                'statistics' => $stats,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Dashboard stats retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve statistics. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user's recent activity feed
     * 📈 ACTIVITY FEED: Recent changes across all boards
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $validated = $request->validate([
                'limit' => 'sometimes|integer|min:1|max:50',
                'board_id' => 'sometimes|integer|exists:boards,id',
                'type' => 'sometimes|string|in:all,my_activity,board_activity',
            ]);
            
            $limit = $validated['limit'] ?? 20;
            $boardId = $validated['board_id'] ?? null;
            $type = $validated['type'] ?? 'all';
            
            // Build activity query
            $query = Activity::with(['user:id,name,avatar', 'board:id,title,color'])
                ->orderBy('created_at', 'desc');
            
            // Apply filters based on type
            switch ($type) {
                case 'my_activity':
                    $query->where('user_id', $user->id);
                    break;
                case 'board_activity':
                    $query->whereHas('board', function ($q) use ($user) {
                        $q->accessibleByUser($user);
                    });
                    break;
                default: // 'all'
                    $query->where(function ($q) use ($user) {
                        $q->where('user_id', $user->id)
                          ->orWhereHas('board', function ($subQ) use ($user) {
                              $subQ->accessibleByUser($user);
                          });
                    });
                    break;
            }
            
            // Filter by specific board
            if ($boardId) {
                $query->where('board_id', $boardId);
            }
            
            $activities = $query->limit($limit)->get();
            
            // Transform activities for response
            $transformedActivities = $activities->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'description' => $activity->description,
                    'created_at' => $activity->created_at,
                    'age' => $activity->age,
                    'user' => [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                        'avatar' => $activity->user->avatar,
                    ],
                    'board' => $activity->board ? [
                        'id' => $activity->board->id,
                        'title' => $activity->board->title,
                        'color' => $activity->board->color,
                    ] : null,
                    'icon' => $activity->icon,
                    'color' => $activity->color,
                ];
            });
            
            return response()->json([
                'message' => "Retrieved {$transformedActivities->count()} recent activities",
                'filters' => [
                    'type' => $type,
                    'board_id' => $boardId,
                    'limit' => $limit,
                ],
                'activities' => $transformedActivities,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Dashboard activity retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve activity feed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user's assigned tasks
     * ✅ MY TASKS: Tasks assigned to the current user
     */
    public function myTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $validated = $request->validate([
                'status' => 'sometimes|string|in:all,pending,in_progress,completed',
                'priority' => 'sometimes|string|in:all,high,medium,low',
                'due_filter' => 'sometimes|string|in:all,overdue,due_today,due_soon,no_due_date',
                'board_id' => 'sometimes|integer|exists:boards,id',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);
            
            $status = $validated['status'] ?? 'all';
            $priority = $validated['priority'] ?? 'all';
            $dueFilter = $validated['due_filter'] ?? 'all';
            $boardId = $validated['board_id'] ?? null;
            $limit = $validated['limit'] ?? 50;
            
            // Build tasks query
            $query = Task::where('assigned_to', $user->id)
                ->whereHas('list.board', function ($q) use ($user) {
                    $q->accessibleByUser($user);
                })
                ->with([
                    'list:id,title,board_id',
                    'list.board:id,title,color',
                    'creator:id,name,avatar'
                ]);
            
            // Apply filters
            if ($status !== 'all') {
                $query->where('status', $status);
            }
            
            if ($priority !== 'all') {
                $query->where('priority', $priority);
            }
            
            if ($boardId) {
                $query->whereHas('list', function ($q) use ($boardId) {
                    $q->where('board_id', $boardId);
                });
            }
            
            // Apply due date filters
            switch ($dueFilter) {
                case 'overdue':
                    $query->whereNotNull('due_date')
                          ->where('due_date', '<', now())
                          ->where('status', '!=', 'completed');
                    break;
                case 'due_today':
                    $query->whereDate('due_date', now());
                    break;
                case 'due_soon':
                    $query->whereNotNull('due_date')
                          ->whereBetween('due_date', [now(), now()->addDays(7)]);
                    break;
                case 'no_due_date':
                    $query->whereNull('due_date');
                    break;
            }
            
            // Get tasks ordered by priority and due date
            $tasks = $query->orderByRaw("
                    CASE 
                        WHEN due_date < NOW() AND status != 'completed' THEN 1
                        WHEN DATE(due_date) = CURDATE() THEN 2
                        WHEN due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 3
                        ELSE 4
                    END
                ")
                ->orderByRaw("
                    CASE priority 
                        WHEN 'high' THEN 1 
                        WHEN 'medium' THEN 2 
                        WHEN 'low' THEN 3 
                    END
                ")
                ->orderBy('due_date')
                ->limit($limit)
                ->get();
            
            // Transform tasks
            $transformedTasks = $tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date,
                    'tags' => $task->tags,
                    'created_at' => $task->created_at,
                    'updated_at' => $task->updated_at,
                    
                    'list' => [
                        'id' => $task->list->id,
                        'title' => $task->list->title,
                    ],
                    'board' => [
                        'id' => $task->list->board->id,
                        'title' => $task->list->board->title,
                        'color' => $task->list->board->color,
                    ],
                    'creator' => [
                        'id' => $task->creator->id,
                        'name' => $task->creator->name,
                        'avatar' => $task->creator->avatar,
                    ],
                    
                    // Status helpers
                    'is_overdue' => $task->isOverdue(),
                    'is_due_today' => $task->due_date && $task->due_date->isToday(),
                    'is_due_soon' => $task->isDueSoon(),
                    'is_completed' => $task->isCompleted(),
                    'is_high_priority' => $task->isHighPriority(),
                    
                    'url' => "/boards/{$task->list->board_id}/tasks/{$task->id}",
                ];
            });
            
            // Get summary stats
            $summary = [
                'total_tasks' => $transformedTasks->count(),
                'overdue_tasks' => $transformedTasks->filter(fn($t) => $t['is_overdue'])->count(),
                'due_today_tasks' => $transformedTasks->filter(fn($t) => $t['is_due_today'])->count(),
                'due_soon_tasks' => $transformedTasks->filter(fn($t) => $t['is_due_soon'])->count(),
                'high_priority_tasks' => $transformedTasks->filter(fn($t) => $t['priority'] === 'high')->count(),
                'completed_tasks' => $transformedTasks->filter(fn($t) => $t['is_completed'])->count(),
            ];
            
            return response()->json([
                'message' => "Retrieved {$transformedTasks->count()} assigned tasks",
                'filters' => [
                    'status' => $status,
                    'priority' => $priority,
                    'due_filter' => $dueFilter,
                    'board_id' => $boardId,
                    'limit' => $limit,
                ],
                'summary' => $summary,
                'tasks' => $transformedTasks,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('My tasks retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve your tasks. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user overview statistics
     */
    private function getUserStats($user, $boardId = null)
    {
        // Base queries
        $boardsQuery = Board::accessibleByUser($user);
        $tasksQuery = Task::whereHas('list.board', function ($q) use ($user) {
            $q->accessibleByUser($user);
        });
        
        // Filter by board if specified
        if ($boardId) {
            $boardsQuery->where('id', $boardId);
            $tasksQuery->whereHas('list', function ($q) use ($boardId) {
                $q->where('board_id', $boardId);
            });
        }
        
        // Get counts
        $totalBoards = $boardsQuery->count();
        $ownedBoards = Board::ownedBy($user)->when($boardId, fn($q) => $q->where('id', $boardId))->count();
        
        $totalTasks = $tasksQuery->count();
        $myTasks = $tasksQuery->where('assigned_to', $user->id)->count();
        $completedTasks = $tasksQuery->where('status', 'completed')->count();
        $overdueTasks = $tasksQuery->whereNotNull('due_date')
                                  ->where('due_date', '<', now())
                                  ->where('status', '!=', 'completed')
                                  ->count();
        
        // Calculate completion rate
        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0;
        
        return [
            'boards' => [
                'total' => $totalBoards,
                'owned' => $ownedBoards,
                'member_of' => $totalBoards - $ownedBoards,
            ],
            'tasks' => [
                'total' => $totalTasks,
                'assigned_to_me' => $myTasks,
                'completed' => $completedTasks,
                'pending' => $tasksQuery->where('status', 'pending')->count(),
                'in_progress' => $tasksQuery->where('status', 'in_progress')->count(),
                'overdue' => $overdueTasks,
                'due_today' => $tasksQuery->whereDate('due_date', now())->count(),
                'due_soon' => $tasksQuery->whereNotNull('due_date')
                                       ->whereBetween('due_date', [now(), now()->addDays(7)])
                                       ->count(),
            ],
            'productivity' => [
                'completion_rate' => $completionRate,
                'high_priority_tasks' => $tasksQuery->where('priority', 'high')->count(),
                'tasks_created_this_week' => $tasksQuery->where('created_at', '>=', now()->startOfWeek())->count(),
                'tasks_completed_this_week' => $tasksQuery->where('status', 'completed')
                                                        ->where('updated_at', '>=', now()->startOfWeek())
                                                        ->count(),
            ],
        ];
    }

    /**
     * Get user's recent boards
     */
    private function getRecentBoards($user, $limit = 5)
    {
        return Board::accessibleByUser($user)
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($board) use ($user) {
                return [
                    'id' => $board->id,
                    'title' => $board->title,
                    'description' => $board->description,
                    'color' => $board->color,
                    'updated_at' => $board->updated_at,
                    'is_owner' => $board->isOwnedBy($user),
                    'role' => $board->getUserRole($user),
                    'tasks_count' => $board->tasks_count,
                    'members_count' => $board->members->count(),
                    'progress_percentage' => $board->progress_percentage,
                    'url' => "/boards/{$board->id}",
                ];
            })
            ->toArray();
    }

    /**
     * Get user's recent tasks
     */
    private function getMyTasks($user, $limit = 10)
    {
        return Task::where('assigned_to', $user->id)
            ->whereHas('list.board', function ($q) use ($user) {
                $q->accessibleByUser($user);
            })
            ->where('status', '!=', 'completed')
            ->with(['list:id,title,board_id', 'list.board:id,title,color'])
            ->orderByRaw("
                CASE 
                    WHEN due_date < NOW() THEN 1
                    WHEN DATE(due_date) = CURDATE() THEN 2
                    WHEN due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 3
                    ELSE 4
                END
            ")
            ->orderByRaw("
                CASE priority 
                    WHEN 'high' THEN 1 
                    WHEN 'medium' THEN 2 
                    WHEN 'low' THEN 3 
                END
            ")
            ->limit($limit)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date,
                    'board' => [
                        'id' => $task->list->board->id,
                        'title' => $task->list->board->title,
                        'color' => $task->list->board->color,
                    ],
                    'is_overdue' => $task->isOverdue(),
                    'is_due_today' => $task->due_date && $task->due_date->isToday(),
                    'is_high_priority' => $task->isHighPriority(),
                    'url' => "/boards/{$task->list->board_id}/tasks/{$task->id}",
                ];
            })
            ->toArray();
    }

    /**
     * Get recent activity
     */
    private function getRecentActivity($user, $limit = 10)
    {
        return Activity::where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereHas('board', function ($subQ) use ($user) {
                      $subQ->accessibleByUser($user);
                  });
            })
            ->with(['user:id,name,avatar', 'board:id,title,color'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'description' => $activity->description,
                    'created_at' => $activity->created_at,
                    'age' => $activity->age,
                    'user' => [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                        'avatar' => $activity->user->avatar,
                    ],
                    'board' => $activity->board ? [
                        'id' => $activity->board->id,
                        'title' => $activity->board->title,
                        'color' => $activity->board->color,
                    ] : null,
                    'icon' => $activity->icon,
                    'color' => $activity->color,
                ];
            })
            ->toArray();
    }

    /**
     * Get notification summary
     */
    private function getNotificationSummary($user)
    {
        $notifications = Notification::forUser($user);
        
        return [
            'total' => $notifications->count(),
            'unread' => $notifications->unread()->count(),
            'high_priority' => $notifications->highPriority()->count(),
            'recent' => $notifications->recent(7)->count(),
        ];
    }

    /**
     * Get date range for period
     */
    private function getDateRange($period)
    {
        $now = now();
        
        return match($period) {
            'week' => [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek(),
            ],
            'month' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
            ],
            'quarter' => [
                'start' => $now->copy()->startOfQuarter(),
                'end' => $now->copy()->endOfQuarter(),
            ],
            'year' => [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
            ],
            default => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
            ],
        };
    }

    /**
     * Get productivity statistics
     */
    private function getProductivityStats($user, $dateRange, $boardId = null)
    {
        $tasksQuery = Task::whereHas('list.board', function ($q) use ($user) {
                $q->accessibleByUser($user);
            })
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']]);
        
        if ($boardId) {
            $tasksQuery->whereHas('list', function ($q) use ($boardId) {
                $q->where('board_id', $boardId);
            });
        }
        
        $completedTasks = $tasksQuery->where('status', 'completed')->count();
        $totalTasks = $tasksQuery->count();
        
        return [
            'tasks_created' => $totalTasks,
            'tasks_completed' => $completedTasks,
            'completion_rate' => $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0,
            'average_completion_time' => 'N/A', // Would need task completion tracking
        ];
    }

    /**
     * Get collaboration statistics
     */
    private function getCollaborationStats($user, $dateRange, $boardId = null)
    {
        // This would be more complex with proper activity tracking
        return [
            'comments_made' => 0,
            'tasks_assigned' => 0,
            'boards_created' => 0,
            'team_interactions' => 0,
        ];
    }

    /**
     * Get trend statistics
     */
    private function getTrendStats($user, $dateRange, $boardId = null)
    {
        // This would require historical data analysis
        return [
            'productivity_trend' => 'stable',
            'completion_trend' => 'improving',
            'activity_trend' => 'increasing',
        ];
    }
}
