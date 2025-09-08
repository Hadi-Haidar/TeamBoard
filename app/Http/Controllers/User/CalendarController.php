<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Board;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Throwable;

class CalendarController extends Controller
{
    /**
     * Get calendar view of tasks
     * 📅 MAIN CALENDAR: Shows all user's tasks by due date
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Validate request parameters
            $validated = $request->validate([
                'month' => 'sometimes|date_format:Y-m',
                'board_id' => 'sometimes|integer|exists:boards,id',
                'view' => 'sometimes|string|in:month,week,day',
                'status' => 'sometimes|string|in:all,pending,in_progress,completed',
                'assigned_to_me' => 'sometimes|boolean',
            ]);
            
            // Parse parameters
            $monthStr = $validated['month'] ?? now()->format('Y-m');
            $boardId = $validated['board_id'] ?? null;
            $view = $validated['view'] ?? 'month';
            $status = $validated['status'] ?? 'all';
            $assignedToMe = $validated['assigned_to_me'] ?? false;
            
            // Parse month
            $month = Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
            $startDate = $month->copy();
            $endDate = $month->copy()->endOfMonth();
            
            // Adjust date range based on view
            switch ($view) {
                case 'week':
                    $startDate = $month->copy()->startOfWeek();
                    $endDate = $month->copy()->endOfWeek();
                    break;
                case 'day':
                    $startDate = $month->copy()->startOfDay();
                    $endDate = $month->copy()->endOfDay();
                    break;
            }
            
            // Build query for tasks with due dates
            $query = Task::whereHas('list.board', function ($q) use ($user) {
                    $q->accessibleByUser($user);
                })
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$startDate, $endDate])
                ->with([
                    'list:id,title,board_id',
                    'list.board:id,title,color',
                    'assignedUser:id,name,avatar',
                    'creator:id,name,avatar'
                ]);
            
            // Apply filters
            if ($boardId) {
                $query->whereHas('list', function ($q) use ($boardId) {
                    $q->where('board_id', $boardId);
                });
            }
            
            if ($status !== 'all') {
                $query->where('status', $status);
            }
            
            if ($assignedToMe) {
                $query->where('assigned_to', $user->id);
            }
            
            // Get tasks
            $tasks = $query->orderBy('due_date')->get();
            
            // Group tasks by date
            $calendarData = $this->groupTasksByDate($tasks, $startDate, $endDate);
            
            // Get summary statistics
            $stats = $this->getCalendarStats($tasks);
            
            return response()->json([
                'message' => 'Calendar data retrieved successfully',
                'calendar' => [
                    'view' => $view,
                    'month' => $monthStr,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'board_id' => $boardId,
                    'status_filter' => $status,
                    'assigned_to_me' => $assignedToMe,
                ],
                'statistics' => $stats,
                'calendar_data' => $calendarData,
                'navigation' => [
                    'prev_month' => $month->copy()->subMonth()->format('Y-m'),
                    'next_month' => $month->copy()->addMonth()->format('Y-m'),
                    'current_month' => now()->format('Y-m'),
                ],
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Calendar retrieval failed', [
                'user_id' => $user->id,
                'month' => $request->get('month'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve calendar data. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get tasks for a specific date
     * 📅 DAILY VIEW: Get all tasks for a specific date
     */
    public function getTasksForDate(Request $request, string $date): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Validate date format
            $carbonDate = Carbon::createFromFormat('Y-m-d', $date);
            
            // Validate additional parameters
            $validated = $request->validate([
                'board_id' => 'sometimes|integer|exists:boards,id',
                'status' => 'sometimes|string|in:all,pending,in_progress,completed',
                'assigned_to_me' => 'sometimes|boolean',
            ]);
            
            $boardId = $validated['board_id'] ?? null;
            $status = $validated['status'] ?? 'all';
            $assignedToMe = $validated['assigned_to_me'] ?? false;
            
            // Build query
            $query = Task::whereHas('list.board', function ($q) use ($user) {
                    $q->accessibleByUser($user);
                })
                ->whereDate('due_date', $carbonDate)
                ->with([
                    'list:id,title,board_id',
                    'list.board:id,title,color',
                    'assignedUser:id,name,avatar',
                    'creator:id,name,avatar',
                    'comments:id,task_id,content,created_at',
                    'attachments:id,task_id,original_name,file_size'
                ]);
            
            // Apply filters
            if ($boardId) {
                $query->whereHas('list', function ($q) use ($boardId) {
                    $q->where('board_id', $boardId);
                });
            }
            
            if ($status !== 'all') {
                $query->where('status', $status);
            }
            
            if ($assignedToMe) {
                $query->where('assigned_to', $user->id);
            }
            
            // Get tasks ordered by priority and time
            $tasks = $query->orderByRaw("
                CASE priority 
                    WHEN 'high' THEN 1 
                    WHEN 'medium' THEN 2 
                    WHEN 'low' THEN 3 
                END
            ")->orderBy('due_date')->get();
            
            // Transform tasks for response
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
                    
                    // List and Board info
                    'list' => [
                        'id' => $task->list->id,
                        'title' => $task->list->title,
                    ],
                    'board' => [
                        'id' => $task->list->board->id,
                        'title' => $task->list->board->title,
                        'color' => $task->list->board->color,
                    ],
                    
                    // Users
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
                    
                    // Status helpers
                    'is_overdue' => $task->isOverdue(),
                    'is_due_today' => $task->due_date && $task->due_date->isToday(),
                    'is_due_soon' => $task->isDueSoon(),
                    'is_completed' => $task->isCompleted(),
                    'is_high_priority' => $task->isHighPriority(),
                    
                    // Counts
                    'comments_count' => $task->comments->count(),
                    'attachments_count' => $task->attachments->count(),
                    
                    // URLs
                    'url' => "/boards/{$task->list->board_id}/tasks/{$task->id}",
                ];
            });
            
            return response()->json([
                'message' => "Found {$transformedTasks->count()} tasks for {$carbonDate->format('M j, Y')}",
                'date' => $date,
                'date_formatted' => $carbonDate->format('l, F j, Y'),
                'filters' => [
                    'board_id' => $boardId,
                    'status' => $status,
                    'assigned_to_me' => $assignedToMe,
                ],
                'tasks' => $transformedTasks,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Daily tasks retrieval failed', [
                'user_id' => $user->id,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve tasks for this date. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get upcoming tasks (next 7 days)
     * ⏰ UPCOMING TASKS: Tasks due in the next week
     */
    public function upcomingTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $validated = $request->validate([
                'days' => 'sometimes|integer|min:1|max:30',
                'board_id' => 'sometimes|integer|exists:boards,id',
                'assigned_to_me' => 'sometimes|boolean',
            ]);
            
            $days = $validated['days'] ?? 7;
            $boardId = $validated['board_id'] ?? null;
            $assignedToMe = $validated['assigned_to_me'] ?? false;
            
            $startDate = now()->startOfDay();
            $endDate = now()->addDays($days)->endOfDay();
            
            // Build query
            $query = Task::whereHas('list.board', function ($q) use ($user) {
                    $q->accessibleByUser($user);
                })
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [$startDate, $endDate])
                ->where('status', '!=', 'completed')
                ->with([
                    'list:id,title,board_id',
                    'list.board:id,title,color',
                    'assignedUser:id,name,avatar'
                ]);
            
            // Apply filters
            if ($boardId) {
                $query->whereHas('list', function ($q) use ($boardId) {
                    $q->where('board_id', $boardId);
                });
            }
            
            if ($assignedToMe) {
                $query->where('assigned_to', $user->id);
            }
            
            // Get tasks ordered by due date and priority
            $tasks = $query->orderBy('due_date')
                          ->orderByRaw("
                              CASE priority 
                                  WHEN 'high' THEN 1 
                                  WHEN 'medium' THEN 2 
                                  WHEN 'low' THEN 3 
                              END
                          ")
                          ->get();
            
            // Group by days
            $groupedTasks = $tasks->groupBy(function ($task) {
                return $task->due_date->format('Y-m-d');
            });
            
            $upcomingData = [];
            for ($i = 0; $i < $days; $i++) {
                $date = now()->addDays($i);
                $dateKey = $date->format('Y-m-d');
                $dayTasks = $groupedTasks->get($dateKey, collect());
                
                $upcomingData[] = [
                    'date' => $dateKey,
                    'date_formatted' => $date->format('l, M j'),
                    'is_today' => $date->isToday(),
                    'is_tomorrow' => $date->isTomorrow(),
                    'day_name' => $date->format('l'),
                    'tasks_count' => $dayTasks->count(),
                    'overdue_count' => $dayTasks->filter(fn($t) => $t->isOverdue())->count(),
                    'high_priority_count' => $dayTasks->filter(fn($t) => $t->priority === 'high')->count(),
                    'tasks' => $dayTasks->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'title' => $task->title,
                            'priority' => $task->priority,
                            'due_date' => $task->due_date,
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
                            'is_overdue' => $task->isOverdue(),
                            'is_high_priority' => $task->isHighPriority(),
                        ];
                    })->values(),
                ];
            }
            
            return response()->json([
                'message' => "Retrieved upcoming tasks for next {$days} days",
                'period' => [
                    'days' => $days,
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                ],
                'filters' => [
                    'board_id' => $boardId,
                    'assigned_to_me' => $assignedToMe,
                ],
                'summary' => [
                    'total_tasks' => $tasks->count(),
                    'overdue_tasks' => $tasks->filter(fn($t) => $t->isOverdue())->count(),
                    'high_priority_tasks' => $tasks->filter(fn($t) => $t->priority === 'high')->count(),
                    'assigned_to_me_tasks' => $tasks->filter(fn($t) => $t->assigned_to === $user->id)->count(),
                ],
                'upcoming_tasks' => $upcomingData,
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Upcoming tasks retrieval failed', [
                'user_id' => $user->id,
                'days' => $request->get('days'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve upcoming tasks. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get overdue tasks
     * 🚨 OVERDUE TASKS: Tasks that are past their due date
     */
    public function overdueTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $validated = $request->validate([
                'board_id' => 'sometimes|integer|exists:boards,id',
                'assigned_to_me' => 'sometimes|boolean',
                'limit' => 'sometimes|integer|min:1|max:100',
            ]);
            
            $boardId = $validated['board_id'] ?? null;
            $assignedToMe = $validated['assigned_to_me'] ?? false;
            $limit = $validated['limit'] ?? 50;
            
            // Build query for overdue tasks
            $query = Task::whereHas('list.board', function ($q) use ($user) {
                    $q->accessibleByUser($user);
                })
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->where('status', '!=', 'completed')
                ->with([
                    'list:id,title,board_id',
                    'list.board:id,title,color',
                    'assignedUser:id,name,avatar',
                    'creator:id,name,avatar'
                ]);
            
            // Apply filters
            if ($boardId) {
                $query->whereHas('list', function ($q) use ($boardId) {
                    $q->where('board_id', $boardId);
                });
            }
            
            if ($assignedToMe) {
                $query->where('assigned_to', $user->id);
            }
            
            // Get overdue tasks ordered by how overdue they are
            $tasks = $query->orderBy('due_date')
                          ->orderByRaw("
                              CASE priority 
                                  WHEN 'high' THEN 1 
                                  WHEN 'medium' THEN 2 
                                  WHEN 'low' THEN 3 
                              END
                          ")
                          ->limit($limit)
                          ->get();
            
            // Transform tasks with overdue information
            $overdueTasks = $tasks->map(function ($task) {
                $daysOverdue = now()->diffInDays($task->due_date);
                
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date,
                    'created_at' => $task->created_at,
                    
                    // Overdue info
                    'days_overdue' => $daysOverdue,
                    'overdue_severity' => $this->getOverdueSeverity($daysOverdue),
                    
                    // List and Board
                    'list' => [
                        'id' => $task->list->id,
                        'title' => $task->list->title,
                    ],
                    'board' => [
                        'id' => $task->list->board->id,
                        'title' => $task->list->board->title,
                        'color' => $task->list->board->color,
                    ],
                    
                    // Users
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
                    
                    // Status
                    'is_high_priority' => $task->isHighPriority(),
                    'url' => "/boards/{$task->list->board_id}/tasks/{$task->id}",
                ];
            });
            
            return response()->json([
                'message' => "Found {$overdueTasks->count()} overdue tasks",
                'filters' => [
                    'board_id' => $boardId,
                    'assigned_to_me' => $assignedToMe,
                    'limit' => $limit,
                ],
                'summary' => [
                    'total_overdue' => $overdueTasks->count(),
                    'high_priority_overdue' => $overdueTasks->filter(fn($t) => $t['priority'] === 'high')->count(),
                    'assigned_to_me_overdue' => $overdueTasks->filter(fn($t) => $t['assigned_user'] && $t['assigned_user']['id'] === $user->id)->count(),
                    'most_overdue_days' => $overdueTasks->max('days_overdue') ?? 0,
                ],
                'overdue_tasks' => $overdueTasks,
            ], Response::HTTP_OK);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Overdue tasks retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve overdue tasks. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Group tasks by date for calendar display
     */
    private function groupTasksByDate($tasks, Carbon $startDate, Carbon $endDate): array
    {
        // Initialize calendar data
        $calendarData = [];
        $current = $startDate->copy();
        
        while ($current <= $endDate) {
            $calendarData[$current->format('Y-m-d')] = [
                'date' => $current->format('Y-m-d'),
                'date_formatted' => $current->format('M j'),
                'day_name' => $current->format('l'),
                'is_today' => $current->isToday(),
                'is_weekend' => $current->isWeekend(),
                'tasks' => [],
                'task_count' => 0,
                'overdue_count' => 0,
                'completed_count' => 0,
                'high_priority_count' => 0,
            ];
            $current->addDay();
        }
        
        // Group tasks by date
        foreach ($tasks as $task) {
            $dateKey = $task->due_date->format('Y-m-d');
            
            if (isset($calendarData[$dateKey])) {
                $calendarData[$dateKey]['tasks'][] = [
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
                    'assigned_user' => $task->assignedUser ? [
                        'id' => $task->assignedUser->id,
                        'name' => $task->assignedUser->name,
                        'avatar' => $task->assignedUser->avatar,
                    ] : null,
                    'is_overdue' => $task->isOverdue(),
                    'is_completed' => $task->isCompleted(),
                    'is_high_priority' => $task->isHighPriority(),
                ];
                
                $calendarData[$dateKey]['task_count']++;
                
                if ($task->isOverdue()) {
                    $calendarData[$dateKey]['overdue_count']++;
                }
                
                if ($task->isCompleted()) {
                    $calendarData[$dateKey]['completed_count']++;
                }
                
                if ($task->isHighPriority()) {
                    $calendarData[$dateKey]['high_priority_count']++;
                }
            }
        }
        
        return array_values($calendarData);
    }

    /**
     * Get calendar statistics
     */
    private function getCalendarStats($tasks): array
    {
        return [
            'total_tasks' => $tasks->count(),
            'completed_tasks' => $tasks->filter(fn($t) => $t->isCompleted())->count(),
            'pending_tasks' => $tasks->filter(fn($t) => $t->status === 'pending')->count(),
            'in_progress_tasks' => $tasks->filter(fn($t) => $t->status === 'in_progress')->count(),
            'overdue_tasks' => $tasks->filter(fn($t) => $t->isOverdue())->count(),
            'high_priority_tasks' => $tasks->filter(fn($t) => $t->priority === 'high')->count(),
            'medium_priority_tasks' => $tasks->filter(fn($t) => $t->priority === 'medium')->count(),
            'low_priority_tasks' => $tasks->filter(fn($t) => $t->priority === 'low')->count(),
        ];
    }

    /**
     * Get overdue severity level
     */
    private function getOverdueSeverity(int $daysOverdue): string
    {
        if ($daysOverdue <= 1) {
            return 'low';        // 1 day overdue
        } elseif ($daysOverdue <= 7) {
            return 'medium';     // 1 week overdue
        } else {
            return 'high';       // More than 1 week overdue
        }
    }
}
