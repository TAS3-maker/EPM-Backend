<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AccessoryAssign;
use App\Models\AccessoryCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AccessoryController extends Controller
{
    // accessory
    public function addaccessory(Request $request)
    {
        try {
            // Validate the input fields
            $validated = $request->validate([
                'category_id' => 'required|exists:accessory_categories,id',
                'brand_name' => 'nullable|max:255',
                'vendor_name' => 'nullable|string|max:255',
                'purchase_date' => 'nullable|date',
                'purchase_amount' => 'nullable|integer|min:0',
                'warranty_months' => 'nullable',
                'images' => 'nullable|array',
                'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
                'condition' => 'nullable|string|max:255',
                'stock_quantity' => 'nullable',
                'notes' => 'nullable|string',
            ]);



            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    // Store each image and get the path
                    $path = $image->store('images/accessories', 'public');
                    $imagePaths[] = $path; 
                }
            }

            $validated['images'] = json_encode($imagePaths);

            $accessory = new Accessory();
            $accessory->category_id = $validated['category_id'];
            $accessory->brand_name = $validated['brand_name'];
            $accessory->vendor_name = $validated['vendor_name'];
            $accessory->purchase_date = $validated['purchase_date'];
            $accessory->purchase_amount = $validated['purchase_amount'];
            $accessory->warranty_months = $validated['warranty_months'];
            $accessory->images = $validated['images'];
            $accessory->condition = $validated['condition'];
            $accessory->notes = $validated['notes'] ?? null;
            $accessory->stock_quantity = $validated['stock_quantity'];
            $accessory->save();

            return response()->json([
                'message' => 'Accessory created successfully.',
                'data' => $accessory,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create accessory.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function allaccessory()
    {
        try {
            $allaccessories = Accessory::orderByDesc('id')->get();
            return response()->json([
                'message' => 'all Accessories retrieved successfully.',
                'data' => $allaccessories
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch all accessories.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getaccessory($id)
    {
        try {
            $accessories = Accessory::with('category')
                ->where('category_id', $id)
                ->orderByDesc('id')
                ->get();

            return response()->json([
                'message' => 'Accessories retrieved successfully.',
                'data' => $accessories
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch accessories.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editaccessory($id)
    {
        try {
            $accessory = Accessory::with('category')->findOrFail($id);

            return response()->json([
                'message' => 'Accessory found.',
                'data' => $accessory
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Accessory not found.'
            ], 404);
        }
    }

    public function updateaccessory(Request $request, $id)
    {
        try {
            // Validate the request data
            $validated = $request->validate([
                'brand_name' => 'nullable|max:255',
                'category_id' => 'required|exists:accessory_categories,id',
                'vendor_name' => 'nullable|string|max:255',
                'condition' => 'nullable|string|max:255',
                'purchase_date' => 'nullable|date',
                'purchase_amount' => 'nullable|integer|min:0',
                'warranty_months' => 'nullable|integer|min:0',
                'images' => 'nullable|array',
                'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
                'notes' => 'nullable|string',
                'stock_quantity' => 'nullable|integer|min:0',
            ]);

            $accessory = Accessory::findOrFail($id);

            // Handle image replacement
            if ($request->hasFile('images')) {
                // Delete existing images
                $existingImages = json_decode($accessory->images, true);
                if ($existingImages) {
                    foreach ($existingImages as $image) {
                        Storage::disk('public')->delete($image);
                    }
                }

                // Upload new images
                $imagePaths = [];
                foreach ($request->file('images') as $image) {
                    $path = $image->store('images/accessories', 'public');
                    $imagePaths[] = $path;
                }

                $validated['images'] = json_encode($imagePaths);
            }

            $accessory->update($validated);

            return response()->json([
                'message' => 'Accessory updated successfully.',
                'data' => $accessory
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update accessory.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteaccessory($id)
    {
        try {
            $accessory = Accessory::findOrFail($id);
            if ($accessory->images) {
                $images = json_decode($accessory->images, true);
                foreach ($images as $image) {
                    Storage::disk('public')->delete($image);
                }
            }
            $accessory->delete();
            return response()->json([
                'message' => 'Accessory and its images deleted successfully.',
                'data' => $accessory
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Accessory not found.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete accessory.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // category accessory
    public function addaccessorycategory(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:accessory_categories,name',
                'category_code' => 'required',
            ]);

            // Create category
            $category = AccessoryCategory::create([
                'name' => $validated['name'],
                'category_code' => $validated['category_code'],
            ]);

            return response()->json([
                'message' => 'Category added successfully!',
                'data' => $category
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to add category', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Something went wrong. Could not add category.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getaccessorycategory()
    {
        try {
            $categories = AccessoryCategory::with(['accessories.assigns'])->get();

            $summary = $categories->map(function ($category) {
                $totalAccessories = $category->accessories->count();
                $totalStock = $category->accessories->sum('stock_quantity');

                $assignedCount = 0;
                $availableCount = 0;
                $trashCount = 0;

                foreach ($category->accessories as $accessory) {
                    $assignedQty = $accessory->assigns->where('status', 'assigned')->count();
                    $trashQty = $accessory->assigns->where('status', 'trash')->count();
                    $assignedCount += $assignedQty;
                    $trashCount += $trashQty;
                    $availableCount += ($accessory->stock_quantity - $assignedQty - $trashQty);
                }

                return [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'category_code' => $category->category_code,
                    'created_at' => $category->created_at,
                    'total_accessories' => $totalAccessories,
                    'total_stock' => $totalStock,
                    'inuse' => $assignedCount,
                    'available_stock' => $availableCount,
                    'trash_stock' => $trashCount,
                ];
            });

            return response()->json([
                'message' => 'Category summary fetched successfully.',
                'data' => $summary
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch category summary.',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function editaccessorycategory($id)
    {
        try {
            $category = AccessoryCategory::find($id);

            if (!$category) {
                return response()->json([
                    'message' => 'Category not found.',
                ], 404);
            }

            // Return response if found
            return response()->json([
                'message' => 'Category data retrieved successfully!',
                'data' => $category
            ], 200);

        } catch (\Exception $e) {
            // Log unexpected errors
            Log::error('Failed to retrieve category', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Something went wrong. Could not retrieve category.',
                'error' => $e->getMessage(), 
            ], 500);
        }
    }

    public function updateaccessorycategory(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:accessory_categories,name,' . $id,
                'category_code' => 'required',
            ]);

            $category = AccessoryCategory::find($id);

            if (!$category) {
                return response()->json([
                    'message' => 'Category not found.'
                ], 404);
            }
            $category->name = $validated['name'];
            $category->category_code = $validated['category_code'];
            $category->save();

            return response()->json([
                'message' => 'Category updated successfully.',
                'data' => $category
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteaccessorycategory($id)
    {
        try {
            $category = AccessoryCategory::find($id);

            if (!$category) {
                return response()->json([
                    'message' => 'Category not found.'
                ], 404);
            }

            $category->delete();

            return response()->json([
                'message' => 'Category deleted successfully.',
                'data' => $category
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong while deleting the category.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // accessory assign
    public function addaccessoryassign(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'accessory_id' => 'required|exists:accessories,id',
                'assigned_at' => 'nullable|date',
                'return_date' => 'nullable|date',
                'condition' => 'nullable',
                'notes' => 'nullable',
                'status' => 'nullable|in:assigned,vacant,in-repair,lost',
            ]);

            $accessory = Accessory::findOrFail($validated['accessory_id']);
            $category = AccessoryCategory::findOrFail($accessory->category_id);
            $categoryCode = $category->category_code;

            $lastAssigned = AccessoryAssign::where('accessory_no', 'LIKE', "{$categoryCode}-%")
                ->orderByRaw("CAST(SUBSTRING_INDEX(accessory_no, '-', -1) AS UNSIGNED) DESC")
                ->first();

            $nextNumber = 1;
            if ($lastAssigned) {
                $lastNumber = (int) substr($lastAssigned->accessory_no, strrpos($lastAssigned->accessory_no, '-') + 1);
                $nextNumber = $lastNumber + 1;
            }

            $accessoryNo = "{$categoryCode}-{$nextNumber}";

            $assignment = new AccessoryAssign();
            $assignment->accessory_no = $accessoryNo;
            $assignment->user_id = $validated['user_id'];
            $assignment->accessory_id = $validated['accessory_id'];
            $assignment->assigned_at = $validated['assigned_at'] ?? null;
            $assignment->return_date = $validated['return_date'] ?? null;
            $assignment->condition = $validated['condition'] ?? null;
            $assignment->notes = $validated['notes'] ?? null;
            $assignment->status = $validated['status'] ?? 'assigned';
            $assignment->save();

            return response()->json([
                'message' => 'Accessory assigned successfully',
                'data' => $assignment
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to assign accessory',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getaccessoryassign()
    {
        try {
            $assignments = AccessoryAssign::with(['user', 'accessory.category'])->get();
            return response()->json(['message' => 'all fetch successfully', 'data' => $assignments],200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve accessory assign', 'error' => $e->getMessage()], 500);
        }
    }

    public function editaccessoryassign($id)
    {
        try {
            $assignment = AccessoryAssign::findOrFail($id);
            return response()->json(['message' => 'get assign', 'data' => $assignment],200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Accessory assignment not found', 'error' => $e->getMessage()], 404);
        }
    }

    public function updateaccessoryassign(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'accessory_no' => 'nullable|max:255',
                'user_id' => 'required|exists:users,id',
                'accessory_id' => 'required|exists:accessories,id',
                'assigned_at' => 'nullable|date',
                'return_date' => 'nullable|date',
                'condition' => 'nullable',
                'notes' => 'nullable',
                'status' => 'nullable|string',
            ]);

            $assignment = AccessoryAssign::findOrFail($id);
            $assignment->user_id = $validated['user_id'];
            $assignment->accessory_id = $validated['accessory_id'];
            $assignment->accessory_no = $validated['accessory_no'] ?? $assignment->accessory_no;
            $assignment->assigned_at = $validated['assigned_at'] ?? $assignment->assigned_at;
            $assignment->assigned_at = $validated['return_date'] ?? $assignment->return_date;
            $assignment->condition = $validated['condition'] ?? null;
            $assignment->notes = $validated['notes'] ?? null;
            $assignment->status = $validated['status'] ?? $assignment->status;
            $assignment->save();

            return response()->json(['message' => 'Accessory assignment updated successfully', 'data' => $assignment],200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update accessory assignment', 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteaccessoryassign($id)
    {
        try {
            $assignment = AccessoryAssign::findOrFail($id);
            $assignment->delete();

            return response()->json(['message' => 'Accessory assignment deleted successfully','data' => $assignment],200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete accessory assignment', 'error' => $e->getMessage()], 500);
        }
    }


}
