<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\ListModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class TaskController extends Controller
{
    /**
     * Get all tasks in a specific list
     */
    public function index(Request $request, string $listId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the list with its board
            $list = ListModel::with('board:id,title,owner_id')->findOrFail($listId);
            
            // Check if user can view this list (through board permissions)
            if (!$list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view tasks in this list.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Get all tasks in this list with relationships
            $tasks = Task::where('list_id', $listId)
                ->with([
                    'assignedUser:id,name,avatar',
                    'creator:id,name,avatar',
                    'comments:id,task_id,user_id,content,created_at',
                    'comments.user:id,name,avatar',
                    'attachments:id,task_id,file_name,file_size,mime_type,created_at'
                ])
                ->orderBy('position')
                ->get()
                ->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'position' => $task->position,
                        'status' => $task->status,
                        'priority' => $task->priority,
                        'due_date' => $task->due_date?->format('Y-m-d'),
                        'tags' => $task->tags ?? [],
                        'created_at' => $task->created_at,
                        'updated_at' => $task->updated_at,
                        
                        // User information
                        'assigned_user' => $task->assignedUser ? [
                            'id' => $task->assignedUser->id,
                            'name' => $task->assignedUser->name,
                            'avatar' => $task->assignedUser->avatar,
                        ] : null,
                        'creator' => $task->creator ? [
                            'id' => $task->creator->id,
                            'name' => $task->creator->name,
                            'avatar' => $task->creator->avatar,
                        ] : null,
                        
                        // Status indicators
                        'is_overdue' => $task->isOverdue(),
                        'is_due_soon' => $task->isDueSoon(),
                        'days_until_due' => $task->days_until_due,
                        
                        // Counts
                        'comments_count' => $task->comments->count(),
                        'attachments_count' => $task->attachments->count(),
                        
                        // Priority and status display
                        'priority_display' => $task->priority_display,
                        'priority_color' => $task->priority_color,
                        'status_display' => $task->status_display,
                    ];
                });
            
            return response()->json([
                'message' => 'Tasks retrieved successfully',
                'tasks' => $tasks,
                'list_info' => [
                    'id' => $list->id,
                    'title' => $list->title,
                    'board_id' => $list->board_id,
                    'tasks_count' => $tasks->count(),
                ],
                'statistics' => [
                    'total_tasks' => $tasks->count(),
                    'pending_tasks' => $tasks->where('status', 'pending')->count(),
                    'in_progress_tasks' => $tasks->where('status', 'in_progress')->count(),
                    'completed_tasks' => $tasks->where('status', 'done')->count(),
                    'overdue_tasks' => $tasks->where('is_overdue', true)->count(),
                    'high_priority_tasks' => $tasks->where('priority', 'high')->count(),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'List not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Tasks retrieval failed', [
                'list_id' => $listId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve tasks. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new task in a list
     */
    public function store(Request $request, string $listId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the list with its board
            $list = ListModel::with('board:id,title,owner_id')->findOrFail($listId);
            
            // Check if user can edit this list (through board permissions)
            if (!$list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to create tasks in this list.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'sometimes|nullable|string|max:5000',
                'assigned_to' => 'sometimes|nullable|exists:users,id',
                'due_date' => 'sometimes|nullable|date|after_or_equal:today',
                'priority' => 'sometimes|in:low,medium,high',
                'tags' => 'sometimes|array|max:10',
                'tags.*' => 'string|max:50',
                'position' => 'sometimes|integer|min:1',
            ]);
            
            // Set position automatically if not provided
            if (!isset($validated['position'])) {
                $maxPosition = Task::where('list_id', $listId)->max('position') ?? 0;
                $validated['position'] = $maxPosition + 1;
            }
            
            // Create the task
            $task = Task::create([
                'list_id' => $listId,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'assigned_to' => $validated['assigned_to'] ?? null,
                'created_by' => $user->id,
                'due_date' => $validated['due_date'] ?? null,
                'priority' => $validated['priority'] ?? 'low',
                'tags' => $validated['tags'] ?? [],
                'position' => $validated['position'],
                'status' => 'pending',
            ]);
            
            // Load relationships for response
            $task->load(['assignedUser:id,name,avatar', 'creator:id,name,avatar']);
            
            return response()->json([
                'message' => 'Task created successfully',
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'position' => $task->position,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'tags' => $task->tags,
                    'list_id' => $task->list_id,
                    'created_at' => $task->created_at,
                    'updated_at' => $task->updated_at,
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
                ]
            ], Response::HTTP_CREATED);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'List not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Task creation failed', [
                'list_id' => $listId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to create task. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific task with full details
     */
    public function show(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with all relationships
            $task = Task::with([
                'list.board:id,title,owner_id',
                'assignedUser:id,name,avatar',
                'creator:id,name,avatar',
                'comments.user:id,name,avatar',
                'attachments:id,task_id,file_name,file_path,file_size,mime_type,created_at'
            ])->findOrFail($taskId);
            
            // Check if user can view this task (through board permissions)
            if (!$task->list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            return response()->json([
                'message' => 'Task retrieved successfully',
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'position' => $task->position,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'tags' => $task->tags ?? [],
                    'created_at' => $task->created_at,
                    'updated_at' => $task->updated_at,
                    
                    // Relationships
                    'list' => [
                        'id' => $task->list->id,
                        'title' => $task->list->title,
                        'board' => [
                            'id' => $task->list->board->id,
                            'title' => $task->list->board->title,
                        ]
                    ],
                    'assigned_user' => $task->assignedUser ? [
                        'id' => $task->assignedUser->id,
                        'name' => $task->assignedUser->name,
                        'avatar' => $task->assignedUser->avatar,
                    ] : null,
                    'creator' => $task->creator ? [
                        'id' => $task->creator->id,
                        'name' => $task->creator->name,
                        'avatar' => $task->creator->avatar,
                    ] : null,
                    
                    // Comments
                    'comments' => $task->comments->map(function($comment) {
                        return [
                            'id' => $comment->id,
                            'content' => $comment->content,
                            'created_at' => $comment->created_at,
                            'user' => [
                                'id' => $comment->user->id,
                                'name' => $comment->user->name,
                                'avatar' => $comment->user->avatar,
                            ]
                        ];
                    }),
                    
                    // Attachments
                    'attachments' => $task->attachments->map(function($attachment) {
                        return [
                            'id' => $attachment->id,
                            'file_name' => $attachment->file_name,
                            'file_size' => $attachment->file_size,
                            'mime_type' => $attachment->mime_type,
                            'created_at' => $attachment->created_at,
                        ];
                    }),
                    
                    // Status indicators
                    'is_overdue' => $task->isOverdue(),
                    'is_due_soon' => $task->isDueSoon(),
                    'days_until_due' => $task->days_until_due,
                    'priority_display' => $task->priority_display,
                    'priority_color' => $task->priority_color,
                    'status_display' => $task->status_display,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Task retrieval failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve task. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update task details
     */
    public function update(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can edit this task (through board permissions)
            if (!$task->list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to edit this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'sometimes|nullable|string|max:5000',
                'assigned_to' => 'sometimes|nullable|exists:users,id',
                'due_date' => 'sometimes|nullable|date',
                'status' => 'sometimes|in:pending,in_progress,done',
                'priority' => 'sometimes|in:low,medium,high',
                'tags' => 'sometimes|array|max:10',
                'tags.*' => 'string|max:50',
            ]);
            
            // Ensure at least one field is provided
            if (empty($validated)) {
                return response()->json([
                    'message' => 'At least one field must be provided for update.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            
            // Update the task
            $task->update($validated);
            
            // Load relationships for response
            $task->load(['assignedUser:id,name,avatar', 'creator:id,name,avatar']);
            
            return response()->json([
                'message' => 'Task updated successfully',
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'position' => $task->position,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'tags' => $task->tags ?? [],
                    'list_id' => $task->list_id,
                    'created_at' => $task->created_at,
                    'updated_at' => $task->updated_at,
                    'assigned_user' => $task->assignedUser ? [
                        'id' => $task->assignedUser->id,
                        'name' => $task->assignedUser->name,
                        'avatar' => $task->assignedUser->avatar,
                    ] : null,
                    'creator' => $task->creator ? [
                        'id' => $task->creator->id,
                        'name' => $task->creator->name,
                        'avatar' => $task->creator->avatar,
                    ] : null,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Task update failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to update task. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a task
     */
    public function destroy(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list, board, and attachments
            $task = Task::with([
                'list.board:id,title,owner_id',
                'attachments:id,task_id,file_path'
            ])->findOrFail($taskId);
            
            // Check if user can edit this task (through board permissions)
            if (!$task->list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to delete this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Store task info for response
            $taskTitle = $task->title;
            $listId = $task->list_id;
            $taskPosition = $task->position;
            
            // Delete attachment files before task deletion
            $this->deleteTaskAttachmentFiles($task);
            
            // Delete the task (cascade will delete comments, attachments records)
            $task->delete();
            
            // Adjust positions of remaining tasks in the list
            Task::where('list_id', $listId)
                ->where('position', '>', $taskPosition)
                ->decrement('position');
            
            return response()->json([
                'message' => "Task '{$taskTitle}' has been deleted successfully.",
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Task deletion failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to delete task. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Move task to another list (drag & drop between lists)
     */
    public function move(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its current list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can edit this task
            if (!$task->list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to move this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'list_id' => 'required|exists:lists,id',
                'position' => 'sometimes|integer|min:1',
            ]);
            
            // Find target list and verify it's in the same board
            $targetList = ListModel::with('board:id')->findOrFail($validated['list_id']);
            
            if ($targetList->board_id !== $task->list->board_id) {
                return response()->json([
                    'message' => 'Cannot move task to a list in a different board.',
                ], Response::HTTP_BAD_REQUEST);
            }
            
            $oldListId = $task->list_id;
            $oldPosition = $task->position;
            $newListId = $validated['list_id'];
            
            // If moving to same list, just reorder
            if ($oldListId === $newListId) {
                return $this->updatePosition($request, $taskId);
            }
            
            // Set new position (end of target list if not specified)
            $newPosition = $validated['position'] ?? (Task::where('list_id', $newListId)->max('position') + 1);
            
            // Update task's list and position
            $task->update([
                'list_id' => $newListId,
                'position' => $newPosition
            ]);
            
            // Adjust positions in old list (move tasks up)
            Task::where('list_id', $oldListId)
                ->where('position', '>', $oldPosition)
                ->decrement('position');
            
            // Adjust positions in new list (make room)
            Task::where('list_id', $newListId)
                ->where('id', '!=', $task->id)
                ->where('position', '>=', $newPosition)
                ->increment('position');
            
            return response()->json([
                'message' => 'Task moved successfully',
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'old_list_id' => $oldListId,
                    'new_list_id' => $newListId,
                    'old_position' => $oldPosition,
                    'new_position' => $newPosition,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task or target list not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Task move failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to move task. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update task position within same list (reorder)
     */
    public function updatePosition(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can edit this task
            if (!$task->list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to reorder this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'position' => 'required|integer|min:1',
            ]);
            
            $newPosition = $validated['position'];
            $oldPosition = $task->position;
            $listId = $task->list_id;
            
            // If position hasn't changed, nothing to do
            if ($newPosition === $oldPosition) {
                return response()->json([
                    'message' => 'Task position unchanged.',
                    'task' => [
                        'id' => $task->id,
                        'title' => $task->title,
                        'position' => $task->position,
                    ]
                ], Response::HTTP_OK);
            }
            
            // Get max position in the list
            $maxPosition = Task::where('list_id', $listId)->max('position');
            
            // Ensure new position is within valid range
            if ($newPosition > $maxPosition) {
                $newPosition = $maxPosition;
            }
            
            // Update positions of other tasks
            if ($newPosition < $oldPosition) {
                // Moving task up - shift others down
                Task::where('list_id', $listId)
                    ->where('position', '>=', $newPosition)
                    ->where('position', '<', $oldPosition)
                    ->increment('position');
            } else {
                // Moving task down - shift others up
                Task::where('list_id', $listId)
                    ->where('position', '>', $oldPosition)
                    ->where('position', '<=', $newPosition)
                    ->decrement('position');
            }
            
            // Update this task's position
            $task->update(['position' => $newPosition]);
            
            return response()->json([
                'message' => 'Task position updated successfully',
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'position' => $newPosition,
                    'old_position' => $oldPosition,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Task position update failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to update task position. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Assign or unassign task to/from user
     */
    public function assign(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can edit this task
            if (!$task->list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to assign this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'assigned_to' => 'nullable|exists:users,id',
            ]);
            
            // Update assignment
            $task->update(['assigned_to' => $validated['assigned_to']]);
            
            // Load user for response
            $task->load('assignedUser:id,name,avatar');
            
            $message = $validated['assigned_to'] 
                ? 'Task assigned successfully'
                : 'Task unassigned successfully';
            
            return response()->json([
                'message' => $message,
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'assigned_user' => $task->assignedUser ? [
                        'id' => $task->assignedUser->id,
                        'name' => $task->assignedUser->name,
                        'avatar' => $task->assignedUser->avatar,
                    ] : null,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Task assignment failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to assign task. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete all attachment files for a task
     */
    private function deleteTaskAttachmentFiles(Task $task): void
    {
        try {
            // Get all attachment file paths
            $attachmentPaths = $task->attachments
                ->pluck('file_path')
                ->filter() // Remove null values
                ->toArray();
            
            // Delete physical files
            if (!empty($attachmentPaths)) {
                Storage::disk('public')->delete($attachmentPaths);
                Log::info('Deleted attachment files for task', [
                    'task_id' => $task->id,
                    'files_count' => count($attachmentPaths),
                ]);
            }
            
        } catch (Throwable $e) {
            // Log but don't fail task deletion for file cleanup issues
            Log::warning('Failed to delete some attachment files', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
