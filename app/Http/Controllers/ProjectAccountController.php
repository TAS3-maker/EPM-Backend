<?php

namespace App\Http\Controllers;

use App\Models\ProjectAccount;
use App\Http\Resources\ProjectAccountResource;
use Illuminate\Http\Request;

class ProjectAccountController extends Controller
{
    public function index()
    {
        return ProjectAccountResource::collection(ProjectAccount::all());
    }

    public function store(Request $request)
    {
        $request->validate([
            'account_name' => 'required|string|max:255',
        ]);

        $account = ProjectAccount::create([
            'account_name' => $request->account_name,
        ]);

        return new ProjectAccountResource($account);
    }

    public function show($id)
    {
        $account = ProjectAccount::findOrFail($id);
        return new ProjectAccountResource($account);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'account_name' => 'required|string|max:255',
        ]);

        $account = ProjectAccount::findOrFail($id);
        $account->update([
            'account_name' => $request->account_name,
        ]);

        return new ProjectAccountResource($account);
    }

    public function destroy($id)
    {
        $account = ProjectAccount::findOrFail($id);
        $account->delete();

        return response()->json(['message' => 'Project account deleted successfully']);
    }
}
