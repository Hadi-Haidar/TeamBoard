<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Events\Notifications\NotificationSent;  // 🚀 REAL-TIME: Import the broadcast event
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationController extends Controller
{
    /**
     * Get user's notifications with filtering
     * 📝 BASIC FUNCTION: Returns user's notification inbox
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Get query parameters for filtering
            $perPage = min($request->get('per_page', 50), 100); // Max 100 per page
            $status = $request->get('status'); // 'read', 'unread', or 'all'
            $type = $request->get('type'); // Filter by notification type
            $priority = $request->get('priority'); // 'high', 'medium', 'low'
            $search = $request->get('search'); // Search in title/message
            
            // Build query
            $query = Notification::forUser($user)->chronological();
            
            // Apply filters
            if ($status === 'read') {
                $query->read();
            } elseif ($status === 'unread') {
                $query->unread();
            }
            // If status is 'all' or not provided, show all notifications
            
            if ($type) {
                $query->ofType($type);
            }
            
            if ($priority === 'high') {
                $query->highPriority();
            }
            
            if ($search) {
                $query->search($search);
            }
            
            // Get paginated results
            $notifications = $query->paginate($perPage);
            
            // Transform notifications for response
            $transformedNotifications = $notifications->getCollection()->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => $notification->type,
                    'data' => $notification->data,
                    'created_at' => $notification->created_at,
                    'read_at' => $notification->read_at,
                    
                    // Status helpers
                    'is_read' => $notification->isRead(),
                    'is_unread' => $notification->isUnread(),
                    'age' => $notification->age,
                    
                    // Display helpers
                    'icon' => $notification->icon,
                    'color' => $notification->color,
                    'priority' => $notification->priority,
                    'formatted_message' => $notification->message, // Skip route generation for now
                    
                    // Action helpers
                    'action_text' => $notification->action_text,
                    'action_url' => null, // Skip route generation for now
                    
                    // Type checks
                    'is_task_assignment' => $notification->isTaskAssignment(),
                    'is_comment' => $notification->isComment(),
                    'is_mention' => $notification->isMention(),
                    'is_board_invitation' => $notification->isBoardInvitation(),
                    'is_due_reminder' => $notification->isDueReminder(),
                ];
            });
            
            return response()->json([
                'message' => 'Notifications retrieved successfully',
                'notifications' => $transformedNotifications,
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'has_more' => $notifications->hasMorePages(),
                ],
                'filters' => [
                    'status' => $status,
                    'type' => $type,
                    'priority' => $priority,
                    'search' => $search,
                ],
                'statistics' => Notification::getUserStats($user),
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Notifications retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve notifications. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific notification
     */
    public function show(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to view this notification.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            return response()->json([
                'message' => 'Notification retrieved successfully',
                'notification' => [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => $notification->type,
                    'data' => $notification->data,
                    'created_at' => $notification->created_at,
                    'updated_at' => $notification->updated_at,
                    'read_at' => $notification->read_at,
                    
                    // Status helpers
                    'is_read' => $notification->isRead(),
                    'is_unread' => $notification->isUnread(),
                    'age' => $notification->age,
                    
                    // Display helpers
                    'icon' => $notification->icon,
                    'color' => $notification->color,
                    'priority' => $notification->priority,
                    'formatted_message' => $notification->message,
                    
                    // Action helpers
                    'action_text' => $notification->action_text,
                    'action_url' => null,
                    
                    // Type checks
                    'is_task_assignment' => $notification->isTaskAssignment(),
                    'is_comment' => $notification->isComment(),
                    'is_mention' => $notification->isMention(),
                    'is_board_invitation' => $notification->isBoardInvitation(),
                    'is_due_reminder' => $notification->isDueReminder(),
                    'is_task_completion' => $notification->isTaskCompletion(),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Notification retrieval failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve notification. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark a notification as read
     * 🚀 REAL-TIME FUNCTION: Broadcasts read status to user's devices
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to modify this notification.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Mark as read
            $wasAlreadyRead = $notification->isRead();
            $notification->markAsRead();
            
            // 🚀 REAL-TIME MAGIC: Broadcast notification read status
            // This updates the notification status across all user's devices
            broadcast(new NotificationSent($notification, 'read'))->toOthers();
            
            return response()->json([
                'message' => $wasAlreadyRead ? 'Notification was already read.' : 'Notification marked as read.',
                'notification' => [
                    'id' => $notification->id,
                    'is_read' => true,
                    'read_at' => $notification->read_at,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Mark notification as read failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to mark notification as read. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark a notification as unread
     * 🚀 REAL-TIME FUNCTION: Broadcasts unread status to user's devices
     */
    public function markAsUnread(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to modify this notification.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Mark as unread
            $wasAlreadyUnread = $notification->isUnread();
            $notification->markAsUnread();
            
            // 🚀 REAL-TIME MAGIC: Broadcast notification unread status
            broadcast(new NotificationSent($notification, 'unread'))->toOthers();
            
            return response()->json([
                'message' => $wasAlreadyUnread ? 'Notification was already unread.' : 'Notification marked as unread.',
                'notification' => [
                    'id' => $notification->id,
                    'is_read' => false,
                    'read_at' => null,
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Mark notification as unread failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to mark notification as unread. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark all notifications as read
     * 🚀 REAL-TIME FUNCTION: Broadcasts bulk read status
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Mark all unread notifications as read
            $updatedCount = Notification::markAllAsReadForUser($user);
            
            // 🚀 REAL-TIME MAGIC: Broadcast bulk read status
            broadcast(new NotificationSent(null, 'mark_all_read', ['user_id' => $user->id]))->toOthers();
            
            return response()->json([
                'message' => "Marked {$updatedCount} notifications as read.",
                'updated_count' => $updatedCount,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Mark all notifications as read failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to mark all notifications as read. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a notification
     * 🚀 REAL-TIME FUNCTION: Broadcasts deletion to user's devices
     */
    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to delete this notification.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Store notification info for response
            $notificationTitle = $notification->title;
            
            // Delete the notification
            $notification->delete();
            
            // 🚀 REAL-TIME MAGIC: Broadcast notification deletion
            broadcast(new NotificationSent(null, 'deleted', ['notification_id' => $notificationId]))->toOthers();
            
            return response()->json([
                'message' => "Notification '{$notificationTitle}' has been deleted successfully.",
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Notification deletion failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to delete notification. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get notification statistics for the user
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        
        try {
            $stats = Notification::getUserStats($user);
            
            return response()->json([
                'message' => 'Notification statistics retrieved successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
                'statistics' => $stats,
            ], Response::HTTP_OK);
            
        } catch (Throwable $e) {
            Log::error('Notification statistics retrieval failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve notification statistics. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Accept board invitation (special action)
     * 🚀 REAL-TIME FUNCTION: Broadcasts invitation acceptance
     */
    public function acceptInvitation(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to accept this invitation.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Check if it's a board invitation
            if (!$notification->isBoardInvitation()) {
                return response()->json([
                    'message' => 'This notification is not a board invitation.',
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Accept the invitation
            $accepted = $notification->acceptInvitation();
            
            if ($accepted) {
                // 🚀 REAL-TIME MAGIC: Broadcast invitation acceptance
                broadcast(new NotificationSent($notification, 'invitation_accepted'))->toOthers();
                
                return response()->json([
                    'message' => 'Board invitation accepted successfully.',
                    'notification' => [
                        'id' => $notification->id,
                        'is_read' => true,
                        'status' => 'accepted',
                    ]
                ], Response::HTTP_OK);
            } else {
                return response()->json([
                    'message' => 'Failed to accept invitation. It may have expired or already been processed.',
                ], Response::HTTP_BAD_REQUEST);
            }
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Accept invitation failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to accept invitation. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Decline board invitation (special action)
     * 🚀 REAL-TIME FUNCTION: Broadcasts invitation decline
     */
    public function declineInvitation(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to decline this invitation.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Check if it's a board invitation
            if (!$notification->isBoardInvitation()) {
                return response()->json([
                    'message' => 'This notification is not a board invitation.',
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Decline the invitation
            $declined = $notification->declineInvitation();
            
            if ($declined) {
                // 🚀 REAL-TIME MAGIC: Broadcast invitation decline
                broadcast(new NotificationSent($notification, 'invitation_declined'))->toOthers();
                
                return response()->json([
                    'message' => 'Board invitation declined successfully.',
                    'notification' => [
                        'id' => $notification->id,
                        'is_read' => true,
                        'status' => 'declined',
                    ]
                ], Response::HTTP_OK);
            } else {
                return response()->json([
                    'message' => 'Failed to decline invitation. It may have expired or already been processed.',
                ], Response::HTTP_BAD_REQUEST);
            }
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Decline invitation failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to decline invitation. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Snooze a notification
     * 🚀 REAL-TIME FUNCTION: Broadcasts snooze action
     */
    public function snooze(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the notification
            $notification = Notification::findOrFail($notificationId);
            
            // Check if notification belongs to the user
            if ($notification->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to snooze this notification.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate snooze duration
            $validated = $request->validate([
                'minutes' => 'sometimes|integer|min:5|max:10080', // 5 minutes to 1 week
            ]);
            
            $minutes = $validated['minutes'] ?? 60; // Default 1 hour
            
            // Snooze the notification
            $snoozed = $notification->snooze($minutes);
            
            if ($snoozed) {
                // 🚀 REAL-TIME MAGIC: Broadcast snooze action
                broadcast(new NotificationSent(null, 'snoozed', [
                    'notification_id' => $notificationId,
                    'minutes' => $minutes,
                ]))->toOthers();
                
                return response()->json([
                    'message' => "Notification snoozed for {$minutes} minutes.",
                    'snoozed_until' => now()->addMinutes($minutes)->toISOString(),
                ], Response::HTTP_OK);
            } else {
                return response()->json([
                    'message' => 'Failed to snooze notification.',
                ], Response::HTTP_BAD_REQUEST);
            }
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Notification not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('Snooze notification failed', [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to snooze notification. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
