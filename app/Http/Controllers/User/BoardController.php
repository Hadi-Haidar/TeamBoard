<?php

namespace App\Http\Controllers\User;

use App\Models\Board;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class BoardController extends Controller
{
    private const BOARD_FIELDS = [
        'id', 'title', 'description', 'color', 'owner_id', 'created_at', 'updated_at'
    ];

    /**
     * Get user's accessible boards with statistics
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Inline validation - simple and direct
        $validated = $request->validate([
            'filter' => 'sometimes|in:owned,member,all',
            'per_page' => 'sometimes|integer|min:5|max:50',
            'search' => 'sometimes|string|max:255',
        ]);
        
        try {
            // Build query with filters
            $query = Board::query();
            
            // Apply filters using your model scopes
            switch ($validated['filter'] ?? 'all') {
                case 'owned':
                    $query->ownedBy($user);
                    break;
                case 'member':
                    $query->accessibleByUser($user)->where('owner_id', '!=', $user->id);
                    break;
                default:
                    $query->accessibleByUser($user);
                    break;
            }
            
            // Add search
            if (!empty($validated['search'])) {
                $search = $validated['search'];
                $query->where(function($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }
            
            // Get boards with relationships
            $boards = $query->with(['owner:id,name,avatar', 'activeMembers:id,board_id,user_id,role'])
                           ->orderBy('updated_at', 'desc')
                           ->paginate($validated['per_page'] ?? 15);
            
            // Format response data
            $boardsData = $this->formatBoardsData($boards, $user);
            $userStats = $this->getUserStats($user);
            
            return response()->json([
                'message' => 'Boards retrieved successfully',
                'boards' => $boardsData,
                'pagination' => [
                    'current_page' => $boards->currentPage(),
                    'per_page' => $boards->perPage(),
                    'total' => $boards->total(),
                ],
                'user_stats' => $userStats,
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            Log::error('Board list retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve boards. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new board
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Inline validation - simple and direct
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'sometimes|string|max:1000',
            'color' => 'sometimes|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ], [
            'title.required' => 'Board title is required.',
            'title.max' => 'Board title cannot exceed 255 characters.',
            'description.max' => 'Description cannot exceed 1000 characters.',
            'color.regex' => 'Color must be a valid hex color code (e.g., #ff0000).',
        ]);
        
        try {
            // Create board with owner automatically set
            $board = Board::create([
                'owner_id' => $user->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'color' => $validated['color'] ?? '#1976d2', // Default blue color
            ]);
            
            // Auto-add owner as member 
            $board->addMember($user, 'owner', $user);

             //load() is used after you already have model(s) in memory.
            // Load relationships for response 
            $board->load([
                'owner:id,name,avatar',
                'activeMembers.user:id,name,avatar'
            ]);
            
            return response()->json([
                'message' => 'Board created successfully',
                'board' => [
                    // Basic info
                    ...$board->only(self::BOARD_FIELDS),
                    'owner' => $board->owner->only(['id', 'name', 'avatar']),
                    
                    // User permissions (owner has all permissions)
                    'user_role' => 'owner',
                    'can_edit' => true,
                    'is_owner' => true,
                    
                    // Initial stats (refresh after adding owner as member)
                    'stats' => [
                        'tasks_count' => 0,
                        'completed_tasks_count' => 0,
                        'progress_percentage' => 0,
                        'lists_count' => 0,
                        'members_count' => $board->activeMembers()->count(), // Fresh count!
                    ],
                    
                    // Empty members preview (owner will appear here now)
                    'members_preview' => [],
                ],
            ], Response::HTTP_CREATED);
            
        } catch (\Exception $e) {
            Log::error('Board creation failed', [
                'user_id' => $user->id,
                'data' => $validated,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to create board. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Format boards with stats and user data
     */
    private function formatBoardsData($boards, $user): array
    {
        return $boards->map(function ($board) use ($user) {//map is used to iterate over the boards and return a new array with the formatted data
            return [
                // Basic info
                ...$board->only(self::BOARD_FIELDS),
                'owner' => $board->owner->only(['id', 'name', 'avatar']),
                
                // User permissions (using your model methods)
                'user_role' => $board->getUserRole($user),
                'can_edit' => $board->canBeEditedBy($user),
                'is_owner' => $board->isOwnedBy($user),
                
                // Stats (using your model attributes)
                'stats' => [
                    'tasks_count' => $board->tasks_count,
                    'completed_tasks_count' => $board->completed_tasks_count,
                    'progress_percentage' => $board->progress_percentage,
                    'members_count' => $board->activeMembers->count(),
                ],
                
                // Members preview
                'members_preview' => $board->activeMembers->take(3)->map(function($member) {
                    return [
                        'id' => $member->user_id,
                        'name' => $member->user->name,
                        'role' => $member->role,
                    ];
                }),
            ];
        })->toArray();
    }

    /**
     * Get user board statistics
     */
    private function getUserStats($user): array
    {
        return [
            'owned_boards_count' => $user->ownedBoards()->count(),
            'member_boards_count' => $user->boardMemberships()->active()->count(),
            'total_accessible_boards' => Board::accessibleByUser($user)->count(),
        ];
    }
//////////////////////////////////////////////////////////////////////////////////////////////
    /**
 * Display a specific board with full details
 */
public function show(Request $request, $id): JsonResponse
{
    $user = $request->user();
    
    try {
        //with() vs load()
        //with() is used before fetching models (on a query): Board::with('owner:id,name,avatar')->paginate(...).
        //load() is used after you already have model(s) in memory.
        
        // Find board with relationships - more efficient than separate queries
        $board = Board::with([
            'owner:id,name,avatar',
            'activeMembers.user:id,name,avatar',
            'lists:id,board_id,title,position',
            'lists.tasks:id,list_id,title,status,position'
        ])->findOrFail($id);
        
        // Check if user can view this board using your model method
        if (!$board->canBeViewedBy($user)) {
            return response()->json([
                'message' => 'You do not have permission to view this board.',
            ], Response::HTTP_FORBIDDEN);
        }
        
        // Format comprehensive board data
        return response()->json([
            'message' => 'Board retrieved successfully',
            'board' => $this->formatDetailedBoardData($board, $user),
        ], Response::HTTP_OK);
        
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'message' => 'Board not found.',
        ], Response::HTTP_NOT_FOUND);
        
    } catch (\Exception $e) {
        Log::error('Board retrieval failed', [
            'board_id' => $id,
            'user_id' => $user->id,
            'error' => $e->getMessage(),
        ]);
        
        return response()->json([
            'message' => 'Failed to retrieve board. Please try again.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

/**
 * Format detailed board data with comprehensive information
 */
private function formatDetailedBoardData($board, $user): array
{
    return [
        // Basic info
        ...$board->only(self::BOARD_FIELDS),
        'owner' => $board->owner->only(['id', 'name', 'avatar']),
        
        // User permissions (using your model methods)
        'user_role' => $board->getUserRole($user),
        'can_edit' => $board->canBeEditedBy($user),
        'can_manage_members' => $board->canManageMembersBy($user),
        'is_owner' => $board->isOwnedBy($user),
        
        // Comprehensive stats
        'stats' => $this->getBoardDetailedStats($board),
        
        // Full members and lists data
        'members' => $this->formatBoardMembers($board->activeMembers),
        'lists' => $this->formatBoardLists($board->lists),
    ];
}

/**
 * Get detailed board statistics
 */
private function getBoardDetailedStats($board): array
{
    return [
        'tasks_count' => $board->tasks_count,
        'completed_tasks_count' => $board->completed_tasks_count,
        'progress_percentage' => $board->progress_percentage,
        'lists_count' => $board->lists->count(),
        'members_count' => $board->activeMembers->count(),
    ];
}

/**
 * Format board members with full details
 */
private function formatBoardMembers($members): array
{
    return $members->map(function($member) {
        return [
            'id' => $member->user_id,
            'name' => $member->user->name,
            'avatar' => $member->user->avatar,
            'role' => $member->role,
            'joined_at' => $member->created_at->format('Y-m-d H:i:s'),
        ];
    })->toArray();
}

/**
 * Format board lists with task previews
 */
private function formatBoardLists($lists): array
{
    return $lists->map(function($list) {
        return [
            'id' => $list->id,
            'title' => $list->title,
            'position' => $list->position,
            'tasks_count' => $list->tasks->count(),
            'completed_tasks_count' => $list->tasks->where('status', 'completed')->count(),
            // Preview of first 3 tasks
            'tasks_preview' => $list->tasks->take(3)->map(function($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'position' => $task->position,
                ];
            })->toArray(),
        ];
    })->toArray();
}
/////////////////////////////////////////////////////////////////////////////////
/**
 * Update board details
 */
public function update(Request $request, $id): JsonResponse
{
    $user = $request->user();
    
    try {
        // Find board
        $board = Board::findOrFail($id);
        
        // Check permissions using your model method
        if (!$board->canBeEditedBy($user)) {
            return response()->json([
                'message' => 'You do not have permission to edit this board.',
            ], Response::HTTP_FORBIDDEN);
        }
        
        // Inline validation - simple and direct
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'color' => 'sometimes|nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
        ], [
            'title.required' => 'Board title is required.',
            'title.max' => 'Board title cannot exceed 255 characters.',
            'description.max' => 'Description cannot exceed 1000 characters.',
            'color.regex' => 'Color must be a valid hex color code (e.g., #ff0000).',
        ]);
        
        // Ensure at least one field is provided
        if (empty($validated)) {
            return response()->json([
                'message' => 'At least one field (title, description, or color) must be provided.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // Update board
        $board->update($validated);
        
        // Load relationships for response
        $board->load(['owner:id,name,avatar']);
        
        return response()->json([
            'message' => 'Board updated successfully',
            'board' => [
                // Basic info
                ...$board->only(self::BOARD_FIELDS),
                'owner' => $board->owner->only(['id', 'name', 'avatar']),
                
                // User permissions
                'user_role' => $board->getUserRole($user),
                'can_edit' => $board->canBeEditedBy($user),
                'is_owner' => $board->isOwnedBy($user),
            ],
        ], Response::HTTP_OK);
        
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'message' => 'Board not found.',
        ], Response::HTTP_NOT_FOUND);
        
    } catch (\Exception $e) {
        Log::error('Board update failed', [
            'board_id' => $id,
            'user_id' => $user->id,
            'data' => $request->all(),
            'error' => $e->getMessage(),
        ]);
        
        return response()->json([
            'message' => 'Failed to update board. Please try again.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
/////////////////////////////////////////////////////////////////////////////////
/**
 * Delete a board and all related data
 */
public function destroy(Request $request, $id): JsonResponse
{
    $user = $request->user();
    
    try {
        // Find board
        $board = Board::findOrFail($id);
        
        // Only board owner can delete board
        if (!$board->isOwnedBy($user)) {
            return response()->json([
                'message' => 'Only the board owner can delete this board.',
            ], Response::HTTP_FORBIDDEN);
        }
        
        // Get board title for response message
        $boardTitle = $board->title;
        
        // Delete attachment files before board deletion (cascade will delete records)
        $this->deleteAttachmentFiles($board);
        
        // Database cascade will handle:
        // - board_members, lists, tasks, comments, attachments (records), activities
        $board->delete();
        
        return response()->json([
            'message' => "Board '{$boardTitle}' has been deleted successfully.",
        ], Response::HTTP_OK);
        
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'message' => 'Board not found.',
        ], Response::HTTP_NOT_FOUND);
        
    } catch (\Exception $e) {
        Log::error('Board deletion failed', [
            'board_id' => $id,
            'user_id' => $user->id,
            'error' => $e->getMessage(),
        ]);
        
        return response()->json([
            'message' => 'Failed to delete board. Please try again.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

/**
 * Delete all attachment files for a board
 */
private function deleteAttachmentFiles(Board $board): void
{
    try {
        // Get all attachment file paths from board tasks
        $attachmentPaths = $board->tasks()
            ->with('attachments:id,file_path')
            ->get()
            ->flatMap->attachments
            ->pluck('file_path')
            ->filter() // Remove null values
            ->toArray();
        
        // Delete physical files
        if (!empty($attachmentPaths)) {
            Storage::disk('public')->delete($attachmentPaths);
            Log::info('Deleted attachment files for board', [
                'board_id' => $board->id,
                'files_count' => count($attachmentPaths),
            ]);
        }
        
    } catch (\Exception $e) {
        // Log but don't fail board deletion for file cleanup issues
        Log::warning('Failed to delete some attachment files', [
            'board_id' => $board->id,
            'error' => $e->getMessage(),
        ]);
    }
}
}