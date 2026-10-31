import type { ApiProblem } from '../api';

/** An API error as the API worded it: what went wrong, the fix for each field, and what to do. */
export function ProblemMessage({ problem }: { problem: ApiProblem }) {
    return (
        <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
            <p className="font-medium">{problem.message}</p>
            {problem.details.length > 0 && (
                <ul className="mt-1 list-disc pl-5">
                    {problem.details.map((detail, i) => (
                        <li key={i}>
                            {detail.message}
                            {detail.did_you_mean !== undefined && <> Did you mean “{detail.did_you_mean}”?</>}
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-1">{problem.nextStep}</p>
        </div>
    );
}
