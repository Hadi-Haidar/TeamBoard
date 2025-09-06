<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Task;
use App\Events\Comments\CommentCreated;  // 🚀 REAL-TIME: Import the broadcast event
use App\Events\Comments\CommentUpdated;    
use App\Events\Comments\CommentDeleted;   
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommentController extends Controller
{
    /**
     * Get all comments for a specific task
     * 📝 BASIC FUNCTION: No real-time features here, just returns existing comments
     */
    public function index(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can view this task (through board permissions)
            if (!$task->list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view comments on this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Get all comments for this task with user information
            $comments = Comment::where('task_id', $taskId)
                ->with('user:id,name,avatar')
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($comment) {
                    return [
                        'id' => $comment->id,
                        'content' => $comment->content,
                        'created_at' => $comment->created_at,
                        'updated_at' => $comment->updated_at,
                        
                        // User information
                        'user' => $comment->user ? [
                            'id' => $comment->user->id,
                            'name' => $comment->user->name,
                            'avatar' => $comment->user->avatar,
                        ] : [
                            'id' => null,
                            'name' => 'Deleted User',
                            'avatar' => null,
                        ],
                        
                        // Timestamps for display
                        'created_at_human' => $comment->created_at->diffForHumans(),
                        'is_edited' => $comment->created_at != $comment->updated_at,
                    ];
                });
            
            return response()->json([
                'message' => 'Comments retrieved successfully',
                'comments' => $comments,
                'task_info' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'comments_count' => $comments->count(),
                ],
                'statistics' => [
                    'total_comments' => $comments->count(),
                    'unique_commenters' => $comments->pluck('user.id')->filter()->unique()->count(),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Comments retrieval failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve comments. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a new comment to a task
     * 🚀 REAL-TIME FUNCTION: This is where the magic happens!
     */
    public function store(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can view this task (through board permissions)
            if (!$task->list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to comment on this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'content' => 'required|string|max:2000',
            ], [
                'content.required' => 'Comment content is required.',
                'content.max' => 'Comment cannot exceed 2000 characters.',
            ]);
            
            // 📝 BASIC: Create the comment in database (same as non-real-time)
            $comment = Comment::create([
                'task_id' => $taskId,
                'user_id' => $user->id,
                'content' => $validated['content'],
            ]);
            
            // 📝 BASIC: Load user relationship for response (same as non-real-time)
            $comment->load('user:id,name,avatar');
            
            // 🚀 REAL-TIME MAGIC: This line broadcasts the comment to all users instantly!
            // Without this line = basic function (users need to refresh to see new comments)
            // With this line = real-time function (users see comments appear instantly)
            broadcast(new CommentCreated($comment))->toOthers();
            
            // 📝 BASIC: Return response to the user who created the comment (same as non-real-time)
            return response()->json([
                'message' => 'Comment added successfully',
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at,
                    'updated_at' => $comment->updated_at,
                    'task_id' => $comment->task_id,
                    
                    // User information
                    'user' => [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                        'avatar' => $comment->user->avatar,
                    ],
                    
                    // Display helpers
                    'created_at_human' => $comment->created_at->diffForHumans(),
                    'is_edited' => false,
                ]
            ], Response::HTTP_CREATED);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Comment creation failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to add comment. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a comment
     * 🚀 REAL-TIME FUNCTION: Broadcasts updates to all users
     */
    public function update(Request $request, string $commentId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the comment with its task, list, and board
            $comment = Comment::with([
                'task.list.board:id,title,owner_id',
                'user:id,name,avatar'
            ])->findOrFail($commentId);
            
            // Check if user can edit this comment
            // Only comment author or board owner can edit
            $canEdit = $comment->user_id === $user->id || 
                      $comment->task->list->board->isOwnedBy($user);
            
            if (!$canEdit) {
                return response()->json([
                    'message' => 'You do not have permission to edit this comment.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate input data
            $validated = $request->validate([
                'content' => 'required|string|max:2000',
            ], [
                'content.required' => 'Comment content is required.',
                'content.max' => 'Comment cannot exceed 2000 characters.',
            ]);
            
            // 📝 BASIC: Update the comment in database (same as non-real-time)
            $comment->update(['content' => $validated['content']]);
            
            // 🚀 REAL-TIME MAGIC: Broadcast the updated comment to all users
            // This makes the edited comment appear instantly for everyone
            broadcast(new CommentUpdated($comment))->toOthers();
            
            return response()->json([
                'message' => 'Comment updated successfully',
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'created_at' => $comment->created_at,
                    'updated_at' => $comment->updated_at,
                    'task_id' => $comment->task_id,
                    
                    // User information
                    'user' => [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                        'avatar' => $comment->user->avatar,
                    ],
                    
                    // Display helpers
                    'created_at_human' => $comment->created_at->diffForHumans(),
                    'updated_at_human' => $comment->updated_at->diffForHumans(),
                    'is_edited' => true,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Comment not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Comment update failed', [
                'comment_id' => $commentId,
                'user_id' => $user->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to update comment. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a comment
     * 🚀 REAL-TIME FUNCTION: Broadcasts deletion to all users
     */
    public function destroy(Request $request, string $commentId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the comment with its task, list, and board
            $comment = Comment::with([
                'task.list.board:id,title,owner_id',
                'user:id,name'
            ])->findOrFail($commentId);
            
            // Check if user can delete this comment
            // Only comment author or board owner can delete
            $canDelete = $comment->user_id === $user->id || 
                        $comment->task->list->board->isOwnedBy($user);
            
            if (!$canDelete) {
                return response()->json([
                    'message' => 'You do not have permission to delete this comment.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Store comment info for response and broadcasting
            $commentAuthor = $comment->user?->name ?? 'Unknown User';
            $taskId = $comment->task_id;
            $commentIdForBroadcast = $comment->id;
            
            // 📝 BASIC: Delete the comment from database (same as non-real-time)
            $comment->delete();
            
            // 🚀 REAL-TIME MAGIC: Broadcast the deletion to all users
            // This makes the comment disappear instantly for everyone
            broadcast(new CommentDeleted($taskId, $commentIdForBroadcast))->toOthers();
            
            return response()->json([
                'message' => "Comment by {$commentAuthor} has been deleted successfully.",
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Comment not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Comment deletion failed', [
                'comment_id' => $commentId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to delete comment. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
