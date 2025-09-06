<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\ListModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListController extends Controller
{
    /**
     * Get all lists in a specific board
     */
    public function index(Request $request, string $boardId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the board first
            $board = Board::findOrFail($boardId);
            
            // Check if user can view this board
            if (!$board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view this board.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Get all lists in this board with task counts
            $lists = ListModel::where('board_id', $boardId)
                ->with(['tasks:id,list_id,status']) // Only load needed task fields
                ->orderBy('position')
                ->get()
                ->map(function ($list) {
                    return [
                        'id' => $list->id,
                        'title' => $list->title,
                        'position' => $list->position,
                        'color' => $list->color,
                        'created_at' => $list->created_at,
                        'updated_at' => $list->updated_at,
                        
                        // Task statistics
                        'tasks_count' => $list->tasks->count(),
                        'completed_tasks_count' => $list->tasks->where('status', 'done')->count(),
                        'pending_tasks_count' => $list->tasks->where('status', 'pending')->count(),
                        'in_progress_tasks_count' => $list->tasks->where('status', 'in_progress')->count(),
                        'progress_percentage' => $list->tasks->count() > 0 
                            ? round(($list->tasks->where('status', 'done')->count() / $list->tasks->count()) * 100)
                            : 0,
                    ];
                });
            
            return response()->json([
                'message' => 'Lists retrieved successfully',
                'lists' => $lists,
                'board_info' => [
                    'id' => $board->id,
                    'title' => $board->title,
                    'lists_count' => $lists->count(),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Board not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Lists retrieval failed', [
                'board_id' => $boardId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve lists. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new list in a board
     */
    public function store(Request $request, string $boardId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the board first
            $board = Board::findOrFail($boardId);
            
            // Check if user can edit this board
            if (!$board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to create lists in this board.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'color' => 'sometimes|nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
                'position' => 'sometimes|integer|min:0',
            ], [
                'title.required' => 'List title is required.',
                'title.max' => 'List title cannot exceed 255 characters.',
                'color.regex' => 'Color must be a valid hex color code (e.g., #ff0000).',
                'position.min' => 'Position must be a positive number.',
            ]);
            
            // Set position automatically if not provided
            if (!isset($validated['position'])) {
                $maxPosition = ListModel::where('board_id', $boardId)->max('position') ?? 0;
                $validated['position'] = $maxPosition + 1;
            }
            
            // Create the list
            $list = ListModel::create([
                'board_id' => $boardId,
                'title' => $validated['title'],
                'color' => $validated['color'] ?? null,
                'position' => $validated['position'],
            ]);
            
            return response()->json([
                'message' => 'List created successfully',
                'list' => [
                    'id' => $list->id,
                    'title' => $list->title,
                    'position' => $list->position,
                    'color' => $list->color,
                    'board_id' => $list->board_id,
                    'created_at' => $list->created_at,
                    'updated_at' => $list->updated_at,
                    
                    // Initial empty statistics
                    'tasks_count' => 0,
                    'completed_tasks_count' => 0,
                    'pending_tasks_count' => 0,
                    'in_progress_tasks_count' => 0,
                    'progress_percentage' => 0,
                ]
            ], Response::HTTP_CREATED);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Board not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('List creation failed', [
                'board_id' => $boardId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to create list. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific list with all its tasks
     */
    public function show(Request $request, string $listId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the list with its board and tasks
            $list = ListModel::with([
                'board:id,title,owner_id',
                'tasks:id,list_id,title,description,position,status,priority,due_date,assigned_to,created_by,created_at,updated_at',
                'tasks.assignedUser:id,name,avatar',
                'tasks.creator:id,name,avatar'
            ])->findOrFail($listId);
            
            // Check if user can view this list (through board permissions)
            if (!$list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view this list.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Format tasks data
            $tasks = $list->tasks->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'position' => $task->position,
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'due_date' => $task->due_date?->format('Y-m-d'),
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
                ];
            });
            
            return response()->json([
                'message' => 'List retrieved successfully',
                'list' => [
                    'id' => $list->id,
                    'title' => $list->title,
                    'position' => $list->position,
                    'color' => $list->color,
                    'created_at' => $list->created_at,
                    'updated_at' => $list->updated_at,
                    
                    // Board information
                    'board' => [
                        'id' => $list->board->id,
                        'title' => $list->board->title,
                    ],
                    
                    // Tasks
                    'tasks' => $tasks,
                    
                    // Statistics
                    'stats' => [
                        'tasks_count' => $list->tasks->count(),
                        'completed_tasks_count' => $list->tasks->where('status', 'done')->count(),
                        'pending_tasks_count' => $list->tasks->where('status', 'pending')->count(),
                        'in_progress_tasks_count' => $list->tasks->where('status', 'in_progress')->count(),
                        'overdue_tasks_count' => $list->tasks->filter(fn($task) => $task->isOverdue())->count(),
                        'progress_percentage' => $list->tasks->count() > 0 
                            ? round(($list->tasks->where('status', 'done')->count() / $list->tasks->count()) * 100)
                            : 0,
                    ]
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'List not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('List retrieval failed', [
                'list_id' => $listId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve list. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update list details (title, color)
     */
    public function update(Request $request, string $listId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the list with its board
            $list = ListModel::with('board:id,title,owner_id')->findOrFail($listId);
            
            // Check if user can edit this list (through board permissions)
            if (!$list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to edit this list.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'color' => 'sometimes|nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            ], [
                'title.required' => 'List title is required.',
                'title.max' => 'List title cannot exceed 255 characters.',
                'color.regex' => 'Color must be a valid hex color code (e.g., #ff0000).',
            ]);
            
            // Ensure at least one field is provided
            if (empty($validated)) {
                return response()->json([
                    'message' => 'At least one field (title or color) must be provided.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            
            // Update the list
            $list->update($validated);
            
            // Reload to get fresh data
            $list->refresh();
            
            return response()->json([
                'message' => 'List updated successfully',
                'list' => [
                    'id' => $list->id,
                    'title' => $list->title,
                    'position' => $list->position,
                    'color' => $list->color,
                    'board_id' => $list->board_id,
                    'created_at' => $list->created_at,
                    'updated_at' => $list->updated_at,
                    
                    // Board information
                    'board' => [
                        'id' => $list->board->id,
                        'title' => $list->board->title,
                    ],
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'List not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('List update failed', [
                'list_id' => $listId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to update list. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update list position (for drag & drop reordering)
     */
    public function updatePosition(Request $request, string $listId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the list with its board
            $list = ListModel::with('board:id,title,owner_id')->findOrFail($listId);
            
            // Check if user can edit this list (through board permissions)
            if (!$list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to reorder lists in this board.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'position' => 'required|integer|min:1',
            ], [
                'position.required' => 'New position is required.',
                'position.integer' => 'Position must be a valid number.',
                'position.min' => 'Position must be at least 1.',
            ]);
            
            $newPosition = $validated['position'];
            $oldPosition = $list->position;
            
            // If position hasn't changed, nothing to do
            if ($newPosition === $oldPosition) {
                return response()->json([
                    'message' => 'List position unchanged.',
                    'list' => [
                        'id' => $list->id,
                        'title' => $list->title,
                        'position' => $list->position,
                    ]
                ], Response::HTTP_OK);
            }
            
            // Get max position in the board
            $maxPosition = ListModel::where('board_id', $list->board_id)->max('position');
            
            // Ensure new position is within valid range
            if ($newPosition > $maxPosition) {
                $newPosition = $maxPosition;
            }
            
            // Update positions of other lists
            if ($newPosition < $oldPosition) {
                // Moving list up - shift others down
                ListModel::where('board_id', $list->board_id)
                    ->where('position', '>=', $newPosition)
                    ->where('position', '<', $oldPosition)
                    ->increment('position');
            } else {
                // Moving list down - shift others up
                ListModel::where('board_id', $list->board_id)
                    ->where('position', '>', $oldPosition)
                    ->where('position', '<=', $newPosition)
                    ->decrement('position');
            }
            
            // Update this list's position
            $list->update(['position' => $newPosition]);
            
            // Get updated list order for response
            $updatedLists = ListModel::where('board_id', $list->board_id)
                ->orderBy('position')
                ->get(['id', 'title', 'position'])
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'position' => $item->position,
                    ];
                });
            
            return response()->json([
                'message' => 'List position updated successfully',
                'list' => [
                    'id' => $list->id,
                    'title' => $list->title,
                    'position' => $newPosition,
                    'old_position' => $oldPosition,
                ],
                'updated_order' => $updatedLists,
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'List not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('List position update failed', [
                'list_id' => $listId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to update list position. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a list and all its tasks
     */
    public function destroy(Request $request, string $listId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the list with its board and tasks
            $list = ListModel::with([
                'board:id,title,owner_id',
                'tasks.attachments:id,task_id,file_path'
            ])->findOrFail($listId);
            
            // Check if user can edit this list (through board permissions)
            if (!$list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to delete this list.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Store list info for response
            $listTitle = $list->title;
            $boardId = $list->board_id;
            $listPosition = $list->position;
            
            // Delete attachment files before list deletion
            $this->deleteListAttachmentFiles($list);
            
            // Delete the list (cascade will delete tasks, comments, attachments records)
            $list->delete();
            
            // Adjust positions of remaining lists in the board
            ListModel::where('board_id', $boardId)
                ->where('position', '>', $listPosition)
                ->decrement('position');
            
            return response()->json([
                'message' => "List '{$listTitle}' has been deleted successfully.",
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'List not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('List deletion failed', [
                'list_id' => $listId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to delete list. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete all attachment files for tasks in this list
     */
    private function deleteListAttachmentFiles(ListModel $list): void
    {
        try {
            // Get all attachment file paths from list tasks
            $attachmentPaths = $list->tasks
                ->flatMap->attachments
                ->pluck('file_path')
                ->filter() // Remove null values
                ->toArray();
            
            // Delete physical files
            if (!empty($attachmentPaths)) {
                Storage::disk('public')->delete($attachmentPaths);
                Log::info('Deleted attachment files for list', [
                    'list_id' => $list->id,
                    'files_count' => count($attachmentPaths),
                ]);
            }
            
        } catch (Throwable $e) {
            // Log but don't fail list deletion for file cleanup issues
            Log::warning('Failed to delete some attachment files', [
                'list_id' => $list->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
