<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ClientsImport;
use App\Http\Helpers\ApiResponse;
use App\Http\Resources\ClientResource;
use Illuminate\Support\Facades\Validator;

class ClientController extends Controller
{
   public function index()
	{
    
		$clients = Client::select('id', 'name', 'client_type', 'contact_detail', 'hire_on_id', 'company_name', 'company_address','communication','created_at', 'updated_at' , 'client_email','client_number')->orderBy('id','DESC')->get();
	    return ApiResponse::success('Clients fetched successfully', ClientResource::collection($clients));
	}


public function store(Request $request)
{
    $rules = [
        'client_type'     => 'required|string|max:255',
        'name'            => 'required|string|max:255|unique:clients,name',
        // 'contact_detail' => 'required|digits_between:10,15|unique:clients,contact_detail',
         'client_email'    => 'nullable|email|max:255|unique:clients,client_email',
        'client_number'   => 'nullable|numeric|digits_between:10,15|unique:clients,client_number',
       // 'project_type'    => 'nullable|string|in:fixed,hourly',
        'communication'   => 'required|string',
        // 'hire_on_id'      => 'nullable|string|max:255|unique:clients,hire_on_id',
        'hire_on_id'      => 'nullable|string|max:255',
        'company_name'    => 'nullable|string|max:255|unique:clients,company_name',
        'company_name' => 'required_unless:client_type,Hired on Upwork|string|max:255|unique:clients,company_name',
        'company_address' => 'nullable|string|max:255',
    ];

    $messages = [
        'name.unique'            => 'A client with this name already exists.',
        'contact_detail.unique'  => 'This contact detail is already in use.',
        'hire_on_id.unique'      => 'This Hire-on ID is already associated with another client.',
        'company_name.unique'    => 'This company name is already used by another client.',
        'client_type.required'   => 'Client type is required.',
        'company_name.required_unless' => 'Company name is required for non-Upwork clients.',
    ];

    $validator = Validator::make($request->all(), $rules, $messages);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    $validated = $validator->validated();

    if ($validated['client_type'] !== 'Hired on Upwork' && empty($validated['company_name'])) {
        return response()->json([
            'success' => false,
            'errors' => [
                'company_name' => ['Company name is required for non-Upwork clients.'],
            ],
        ], 422);
    }

    $client = Client::create($validated);

    return response()->json([
        'success' => true,
        'message' => 'Client created successfully',
        'data'    => $client,
    ]);
}

public function update(Request $request, $id)
{
    $client = Client::find($id);

    if (!$client) {
        return response()->json([
            'success' => false,
            'message' => 'Client not found'
        ], 404);
    }

    // Base validation rules
    $rules = [
        'client_type'     => 'required|string|max:255',
        'name'            => 'required|string|max:255|unique:clients,name,' . $id,
        // 'contact_detail'  => 'nullable|string|max:255|unique:clients,contact_detail,' . $id,
         'client_email'    => 'nullable|email|max:255|unique:clients,client_email,' . $id,
        'client_number'   => 'nullable|numeric|digits_between:10,15|unique:clients,client_number,' . $id,
       // 'project_type'    => 'nullable|string|in:fixed,hourly',
        'communication'   => 'nullable|string',
        'hire_on_id'      => 'nullable|string|max:255',
        'company_name'    => 'nullable|string|max:255|unique:clients,company_name,' . $id,
        'company_address' => 'nullable|string|max:255',
    ];

    $messages = [
        'name.unique'            => 'A client with this name already exists.',
        'contact_detail.unique'  => 'This contact detail is already in use.',
        'hire_on_id.unique'      => 'This Hire-on ID is already associated with another client.',
        'company_name.unique'    => 'This company name is already used by another client.',
        'client_type.required'   => 'Client type is required.',
    ];

    // Validate request
    $validated = $request->validate($rules, $messages);

    if ($validated['client_type'] !== 'Hired on Upwork' && empty($validated['company_name'])) {
        return response()->json([
            'success' => false,
            'message' => 'Company name is required for non-Upwork clients.',
        ], 422);
    }

    $client->update($validated);

    return response()->json([
        'success' => true,
        'message' => 'Client updated successfully',
        'data'    => $client
    ]);
}

    public function destroy($id)
    {
        $client = Client::find($id);
        if (!$client) {
            return ApiResponse::error('Client not found', [], 404);
        }
        $client->delete();
        return ApiResponse::success('Client deleted successfully');
    }

    
public function importCsv(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:csv,txt',
    ]);

    $file = $request->file('file');
    $path = $file->getRealPath();

    $handle = fopen($path, 'r');
    $header = fgetcsv($handle); 
    $successCount = 0;
    $skippedCount = 0;
    $skippedDetails = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) == count($header)) {
            $clientData = array_combine($header, $row);

            if (empty($clientData['name'])) {
                $skippedCount++;
                $skippedDetails[] = [
                    'row_data' => $clientData,
                    'reason'   => 'Missing name',
                ];
                continue;
            }

            $exists = Client::where('name', $clientData['name'])
                        ->orWhere('contact_detail', $clientData['contact_detail'] ?? '')
                        ->exists();

            if ($exists) {
                $skippedCount++;
                $skippedDetails[] = [
                    'row_data' => $clientData,
                    'reason'   => 'Duplicate entry (name or contact_detail)',
                ];
                continue;
            }

            try {
                Client::create([
                    'name'             => $clientData['name'],
                    'client_type'      => $clientData['client_type'] ?? null,
                    'contact_detail'   => $clientData['contact_detail'] ?? null,
                    'hire_on_id'       => $clientData['hire_on_id'] ?? null,
                    'company_name'     => $clientData['company_name'] ?? null,
                    'company_address'  => $clientData['company_address'] ?? null,
                  //'project_type'     => $clientData['project_type'] ?? null,
                    'communication'    => $clientData['communication'] ?? null,
                ]);
                $successCount++;
            } catch (\Exception $e) {
                $skippedCount++;
                $skippedDetails[] = [
                    'row_data' => $clientData,
                    'reason'   => 'DB Insert Failed: ' . $e->getMessage(),
                ];
            }
        } else {
            $skippedCount++;
            $skippedDetails[] = [
                'row_data' => $row,
                'reason'   => 'Invalid column count (mismatch with header)',
            ];
        }
    }

    fclose($handle);

    return response()->json([
        'success'         => true,
        'message'         => 'Client import completed.',
        'inserted_count'  => $successCount,
        'skipped_count'   => $skippedCount,
        'skipped_details' => $skippedDetails,
    ]);
}
}
