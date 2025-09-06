<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Events\Attachments\AttachmentUploaded;
use App\Events\Attachments\AttachmentDeleted;

class AttachmentController extends Controller
{
    /**
     * Get all attachments for a specific task
     * 📝 BASIC FUNCTION: No real-time features needed for listing files
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
                    'message' => 'You do not have permission to view attachments on this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Get all attachments for this task with user information
            $attachments = Attachment::where('task_id', $taskId)
                ->with('user:id,name,avatar')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'file_name' => $attachment->file_name,
                        'file_size' => $attachment->file_size,
                        'file_size_human' => $this->formatFileSize($attachment->file_size),
                        'mime_type' => $attachment->mime_type,
                        'created_at' => $attachment->created_at,
                        'updated_at' => $attachment->updated_at,
                        
                        // User who uploaded
                        'uploaded_by' => $attachment->user ? [
                            'id' => $attachment->user->id,
                            'name' => $attachment->user->name,
                            'avatar' => $attachment->user->avatar,
                        ] : [
                            'id' => null,
                            'name' => 'Unknown User',
                            'avatar' => null,
                        ],
                        
                        // File type info
                        'is_image' => $this->isImageFile($attachment->mime_type),
                        'file_extension' => pathinfo($attachment->file_name, PATHINFO_EXTENSION),
                        'created_at_human' => $attachment->created_at->diffForHumans(),
                        
                        // Download URL
                        'download_url' => route('attachments.download', $attachment->id),
                    ];
                });
            
            return response()->json([
                'message' => 'Attachments retrieved successfully',
                'attachments' => $attachments,
                'task_info' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'attachments_count' => $attachments->count(),
                ],
                'statistics' => [
                    'total_attachments' => $attachments->count(),
                    'total_size' => $attachments->sum('file_size'),
                    'total_size_human' => $this->formatFileSize($attachments->sum('file_size')),
                    'image_count' => $attachments->where('is_image', true)->count(),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Attachments retrieval failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve attachments. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload a new file to a task
     * 🚀 REAL-TIME FUNCTION: Broadcasts file upload to all users viewing the task
     */
    public function store(Request $request, string $taskId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the task with its list and board
            $task = Task::with('list.board:id,title,owner_id')->findOrFail($taskId);
            
            // Check if user can edit this task (through board permissions)
            if (!$task->list->board->canBeEditedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to upload files to this task.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate file upload
            $validated = $request->validate([
                'file' => [
                    'required',
                    'file',
                    'max:10240', // 10MB max
                    'mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt,zip,rar'
                ],
            ], [
                'file.required' => 'Please select a file to upload.',
                'file.max' => 'File size cannot exceed 10MB.',
                'file.mimes' => 'File type not supported. Allowed: images, PDF, Office documents, text files, archives.',
            ]);
            
            $uploadedFile = $request->file('file');
            
            // Generate unique filename
            $originalName = $uploadedFile->getClientOriginalName();
            $extension = $uploadedFile->getClientOriginalExtension();
            $filename = time() . '_' . uniqid() . '.' . $extension;
            
            // Store file in public/attachments directory
            $filePath = $uploadedFile->storeAs('attachments', $filename, 'public');
            
            // 📝 BASIC: Create attachment record in database
            $attachment = Attachment::create([
                'task_id' => $taskId,
                'user_id' => $user->id,
                'file_name' => $originalName,
                'file_path' => $filePath,
                'file_size' => $uploadedFile->getSize(),
                'mime_type' => $uploadedFile->getMimeType(),
            ]);
            
            // Load user relationship for response
            $attachment->load('user:id,name,avatar');
            
            // 🚀 REAL-TIME MAGIC: Broadcast file upload to all users viewing this task
            // This makes the new attachment appear instantly for everyone
            broadcast(new AttachmentUploaded($attachment))->toOthers();
            
            return response()->json([
                'message' => 'File uploaded successfully',
                'attachment' => [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_size' => $attachment->file_size,
                    'file_size_human' => $this->formatFileSize($attachment->file_size),
                    'mime_type' => $attachment->mime_type,
                    'task_id' => $attachment->task_id,
                    'created_at' => $attachment->created_at,
                    'updated_at' => $attachment->updated_at,
                    
                    // User information
                    'uploaded_by' => [
                        'id' => $attachment->user->id,
                        'name' => $attachment->user->name,
                        'avatar' => $attachment->user->avatar,
                    ],
                    
                    // File info
                    'is_image' => $this->isImageFile($attachment->mime_type),
                    'file_extension' => pathinfo($attachment->file_name, PATHINFO_EXTENSION),
                    'created_at_human' => $attachment->created_at->diffForHumans(),
                    'download_url' => route('attachments.download', $attachment->id),
                ]
            ], Response::HTTP_CREATED);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Task not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The uploaded file is invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
            
        } catch (Throwable $e) {
            Log::error('File upload failed', [
                'task_id' => $taskId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to upload file. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get specific attachment details
     * 📝 BASIC FUNCTION: Just returns attachment info
     */
    public function show(Request $request, string $attachmentId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the attachment with its task, list, and board
            $attachment = Attachment::with([
                'task.list.board:id,title,owner_id',
                'user:id,name,avatar'
            ])->findOrFail($attachmentId);
            
            // Check if user can view this attachment (through board permissions)
            if (!$attachment->task->list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to view this attachment.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            return response()->json([
                'message' => 'Attachment retrieved successfully',
                'attachment' => [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_size' => $attachment->file_size,
                    'file_size_human' => $this->formatFileSize($attachment->file_size),
                    'mime_type' => $attachment->mime_type,
                    'created_at' => $attachment->created_at,
                    'updated_at' => $attachment->updated_at,
                    
                    // Task information
                    'task' => [
                        'id' => $attachment->task->id,
                        'title' => $attachment->task->title,
                        'board' => [
                            'id' => $attachment->task->list->board->id,
                            'title' => $attachment->task->list->board->title,
                        ]
                    ],
                    
                    // User who uploaded
                    'uploaded_by' => $attachment->user ? [
                        'id' => $attachment->user->id,
                        'name' => $attachment->user->name,
                        'avatar' => $attachment->user->avatar,
                    ] : null,
                    
                    // File info
                    'is_image' => $this->isImageFile($attachment->mime_type),
                    'file_extension' => pathinfo($attachment->file_name, PATHINFO_EXTENSION),
                    'created_at_human' => $attachment->created_at->diffForHumans(),
                    'download_url' => route('attachments.download', $attachment->id),
                ]
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Attachment not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Attachment retrieval failed', [
                'attachment_id' => $attachmentId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to retrieve attachment. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download attachment file
     * 📝 BASIC FUNCTION: Serves file for download
     */
    public function download(Request $request, string $attachmentId): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the attachment with its task, list, and board
            $attachment = Attachment::with([
                'task.list.board:id,title,owner_id'
            ])->findOrFail($attachmentId);
            
            // Check if user can view this attachment (through board permissions)
            if (!$attachment->task->list->board->canBeViewedBy($user)) {
                return response()->json([
                    'message' => 'You do not have permission to download this attachment.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Check if file exists
            if (!Storage::disk('public')->exists($attachment->file_path)) {
                return response()->json([
                    'message' => 'File not found on server.',
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Get full file path
            $fullPath = Storage::disk('public')->path($attachment->file_path);
            
            // Return file download response
            return response()->download($fullPath, $attachment->file_name);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Attachment not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('File download failed', [
                'attachment_id' => $attachmentId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to download file. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete an attachment
     * 🚀 REAL-TIME FUNCTION: Broadcasts deletion to all users viewing the task
     */
    public function destroy(Request $request, string $attachmentId): JsonResponse
    {
        $user = $request->user();
        
        try {
            // Find the attachment with its task, list, and board
            $attachment = Attachment::with([
                'task.list.board:id,title,owner_id',
                'user:id,name'
            ])->findOrFail($attachmentId);
            
            // Check if user can delete this attachment
            // Only uploader or board owner can delete
            $canDelete = $attachment->user_id === $user->id || 
                        $attachment->task->list->board->isOwnedBy($user);
            
            if (!$canDelete) {
                return response()->json([
                    'message' => 'You do not have permission to delete this attachment.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Store attachment info for response and broadcasting
            $fileName = $attachment->file_name;
            $taskId = $attachment->task_id;
            $attachmentIdForBroadcast = $attachment->id;
            $filePath = $attachment->file_path;
            
            // 📝 BASIC: Delete the attachment record from database
            $attachment->delete();
            
            // 📝 BASIC: Delete physical file from storage
            if (Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
            }
            
            // 🚀 REAL-TIME MAGIC: Broadcast deletion to all users viewing this task
            // This makes the attachment disappear instantly for everyone
            broadcast(new AttachmentDeleted($taskId, $attachmentIdForBroadcast))->toOthers();
            
            return response()->json([
                'message' => "Attachment '{$fileName}' has been deleted successfully.",
            ], Response::HTTP_OK);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Attachment not found.',
            ], Response::HTTP_NOT_FOUND);
            
        } catch (Throwable $e) {
            Log::error('Attachment deletion failed', [
                'attachment_id' => $attachmentId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to delete attachment. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Helper method to format file size in human-readable format
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Helper method to check if file is an image
     */
    private function isImageFile(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }
}
