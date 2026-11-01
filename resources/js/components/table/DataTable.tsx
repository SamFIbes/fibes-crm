import { flexRender, getCoreRowModel, useReactTable, type ColumnDef } from '@tanstack/react-table';
import type { ReactNode } from 'react';

export function DataTable<T>({ columns, data, empty }: { columns: ColumnDef<T, unknown>[]; data: T[]; empty?: ReactNode }) {
    const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });
    const rows = table.getRowModel().rows;

    return (
        <div className="card overflow-x-auto" data-testid="data-table">
            <table className="w-full min-w-[640px] text-start">
                <thead>
                    {table.getHeaderGroups().map((group) => (
                        <tr key={group.id} className="border-b border-line text-start text-2xs font-bold uppercase tracking-[0.1em] text-faint">
                            {group.headers.map((header) => (
                                <th key={header.id} scope="col" className="px-4 py-3 text-start font-bold">
                                    {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                </th>
                            ))}
                        </tr>
                    ))}
                </thead>
                <tbody>
                    {rows.length === 0 ? (
                        <tr>
                            <td colSpan={columns.length} className="px-4 py-12 text-center text-muted">
                                {empty}
                            </td>
                        </tr>
                    ) : (
                        rows.map((row) => (
                            <tr key={row.id} className="border-b border-line text-md last:border-0 hover:bg-well">
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="px-4 py-3 align-middle">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}
