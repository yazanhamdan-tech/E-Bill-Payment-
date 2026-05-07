import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { invoicesApi } from '@/lib/api/invoices';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { 
  Select, 
  SelectContent, 
  SelectItem, 
  SelectTrigger, 
  SelectValue 
} from '@/components/ui/select';
import { EmptyState } from '@/components/EmptyState';
import { LoadingSpinner } from '@/components/LoadingSpinner';
import { 
  Plus, Search, FileText, Download, Eye, Edit, Trash2, Filter, Archive, ArchiveRestore, Zap
} from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { format } from 'date-fns';
import { useAuth } from '@/contexts/AuthContext';
import { toast } from 'sonner';

interface Invoice {
  id: number;
  invoice_number: string;
  title: string;
  amount: number;
  tax_amount: number;
  total_amount: number;
  status: 'pending' | 'paid' | 'overdue' | 'cancelled' | 'archived';
  display_status?: 'pending' | 'paid' | 'partially_paid' | 'overdue' | 'cancelled' | 'archived';
  is_partially_paid?: boolean;
  total_paid?: number;
  remaining_amount?: number;
  due_date: string;
  issue_date: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  service_provider?: {
    id: number;
    name?: string;
    company_name?: string;
  };
  is_recurring?: boolean;
  auto_pay_enabled?: boolean;
}

export default function InvoiceList() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [includeArchived, setIncludeArchived] = useState(false);
  const canCreate = user?.role === 'admin' || user?.role === 'service_provider' || user?.roles?.some((r: any) => r.name === 'service_provider');

  useEffect(() => {
    loadInvoices();
  }, [statusFilter, includeArchived]);

  const loadInvoices = async () => {
    try {
      setLoading(true);
      const params: any = {};
      
      if (statusFilter !== 'all') {
        params.status = statusFilter;
      }
      
      if (includeArchived) {
        params.include_archived = '1';
      }
      
      if (search) {
        params.search = search;
      }

      const response = await invoicesApi.getAll(params);
      
      console.log('Invoice API Response:', response);
      console.log('Response data:', response.data);
      console.log('Response data type:', typeof response.data);
      console.log('Is array?', Array.isArray(response.data));
      
      if (response.data) {
        // Handle paginated response - Laravel returns { data: [...], current_page: 1, ... }
        // API client wraps it: { data: { data: [...], current_page: 1, ... } }
        if (Array.isArray(response.data)) {
          // Direct array response (shouldn't happen with pagination, but handle it)
          console.log('Got direct array response, count:', response.data.length);
          setInvoices(response.data);
        } else if (response.data.data && Array.isArray(response.data.data)) {
          // Paginated response: { data: { data: [...], current_page: 1, ... } }
          console.log('Got paginated response, count:', response.data.data.length, 'total:', response.data.total);
          setInvoices(response.data.data);
        } else if (response.data.invoices && Array.isArray(response.data.invoices)) {
          // Alternative response format: { invoices: [...] }
          console.log('Got invoices array, count:', response.data.invoices.length);
          setInvoices(response.data.invoices);
        } else {
          console.warn('Unexpected response format:', response.data);
          console.warn('Response keys:', Object.keys(response.data || {}));
          console.warn('Response.data keys:', response.data ? Object.keys(response.data) : 'N/A');
          setInvoices([]);
        }
      } else {
        console.warn('No data in response:', response);
        if (response.errors) {
          console.warn('Response errors:', response.errors);
        }
        setInvoices([]);
      }
    } catch (error: any) {
      console.error('Error loading invoices:', error);
      toast.error(error?.message || 'Failed to load invoices');
      setInvoices([]);
    } finally {
      setLoading(false);
    }
  };

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      if (search !== undefined) {
        loadInvoices();
      }
    }, 500);

    return () => clearTimeout(timer);
  }, [search]);

  // Client-side filtering only for search (status filtering is done on backend)
  const filteredInvoices = invoices.filter((invoice) => {
    if (!search) return true;
    
    return (
      invoice.invoice_number?.toLowerCase().includes(search.toLowerCase()) ||
      invoice.title?.toLowerCase().includes(search.toLowerCase()) ||
      invoice.user?.name?.toLowerCase().includes(search.toLowerCase())
    );
  });

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this invoice?')) {
      return;
    }

    try {
      await invoicesApi.delete(id);
      toast.success('Invoice deleted successfully');
      loadInvoices();
    } catch (error: any) {
      console.error('Error deleting invoice:', error);
      toast.error(error?.message || 'Failed to delete invoice');
    }
  };

  const handleArchive = async (id: number) => {
    if (!confirm('Are you sure you want to archive this invoice?')) {
      return;
    }

    try {
      await invoicesApi.archive(id);
      toast.success('Invoice archived successfully');
      loadInvoices();
    } catch (error: any) {
      console.error('Error archiving invoice:', error);
      toast.error(error?.message || 'Failed to archive invoice');
    }
  };

  const handleUnarchive = async (id: number) => {
    try {
      await invoicesApi.unarchive(id);
      toast.success('Invoice unarchived successfully');
      loadInvoices();
    } catch (error: any) {
      console.error('Error unarchiving invoice:', error);
      toast.error(error?.message || 'Failed to unarchive invoice');
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner />
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Invoices</h1>
          <p className="text-muted-foreground mt-1">
            Manage and track all your invoices
          </p>
        </div>
        {canCreate && (
          <Button asChild>
            <Link to="/invoices/create">
              <Plus className="h-4 w-4 mr-2" />
              Create Invoice
            </Link>
          </Button>
        )}
      </div>

      {/* Filters */}
      <div className="flex flex-col sm:flex-row gap-4">
        <div className="relative flex-1">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search invoices..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pl-10"
          />
        </div>
        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="w-full sm:w-[180px]">
            <Filter className="h-4 w-4 mr-2" />
            <SelectValue placeholder="Filter by status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Status</SelectItem>
            <SelectItem value="pending">Pending</SelectItem>
            <SelectItem value="partially_paid">Partially Paid</SelectItem>
            <SelectItem value="paid">Paid</SelectItem>
            <SelectItem value="overdue">Overdue</SelectItem>
            <SelectItem value="cancelled">Cancelled</SelectItem>
            <SelectItem value="archived">Archived</SelectItem>
          </SelectContent>
        </Select>
        <div className="flex items-center space-x-2">
          <Checkbox 
            id="include-archived" 
            checked={includeArchived}
            onCheckedChange={(checked) => setIncludeArchived(checked === true)}
          />
          <Label htmlFor="include-archived" className="text-sm cursor-pointer">
            Include Archived
          </Label>
        </div>
      </div>

      {/* Table */}
      {filteredInvoices.length === 0 ? (
        <EmptyState
          icon={FileText}
          title="No invoices found"
          description={invoices.length === 0 
            ? "You haven't created any invoices yet. Create your first invoice to get started."
            : "No invoices match your search criteria. Try adjusting your filters."}
        />
      ) : (
        <div className="rounded-xl border border-border bg-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Customer</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Due Date</th>
                  <th className="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredInvoices.map((invoice) => (
                  <tr key={invoice.id}>
                    <td>
                      <div>
                        <div className="flex items-center gap-2">
                          <p className="font-medium">{invoice.invoice_number}</p>
                          {invoice.auto_pay_enabled && (
                            <Zap className="h-4 w-4 text-success" title="Auto-pay enabled" />
                          )}
                        </div>
                        <p className="text-sm text-muted-foreground">{invoice.title}</p>
                      </div>
                    </td>
                    <td>
                      <p className="font-medium">{invoice.user?.name || 'N/A'}</p>
                      {invoice.user?.email && (
                        <p className="text-xs text-muted-foreground">{invoice.user.email}</p>
                      )}
                    </td>
                    <td>
                      <p className="font-semibold">${invoice.total_amount.toLocaleString()}</p>
                      {(invoice.tax_amount > 0 || invoice.fee_amount > 0) && (
                        <p className="text-xs text-muted-foreground">
                          {invoice.tax_amount > 0 && `Tax: $${invoice.tax_amount.toLocaleString()}`}
                          {invoice.tax_amount > 0 && invoice.fee_amount > 0 && ' • '}
                          {invoice.fee_amount > 0 && `Fee: $${invoice.fee_amount.toLocaleString()}`}
                        </p>
                      )}
                    </td>
                    <td>
                      <div className="flex items-center gap-2">
                        <StatusBadge status={invoice.display_status || invoice.status} />
                        {invoice.dispute_status && invoice.dispute_status !== 'none' && (
                          <span 
                            className={`text-xs px-2 py-1 rounded-full ${
                              invoice.dispute_status === 'pending' || invoice.dispute_status === 'under_review'
                                ? 'bg-warning/20 text-warning border border-warning/30'
                                : invoice.dispute_status === 'resolved'
                                ? 'bg-success/20 text-success border border-success/30'
                                : 'bg-error/20 text-error border border-error/30'
                            }`}
                            title={`Dispute: ${invoice.dispute_status}`}
                          >
                            ⚠️ Disputed
                          </span>
                        )}
                      </div>
                    </td>
                    <td>
                      <p className="text-sm">
                        {invoice.due_date ? format(new Date(invoice.due_date), 'MMM d, yyyy') : 'N/A'}
                      </p>
                    </td>
                    <td>
                      <div className="flex items-center justify-end gap-2">
                        <Button variant="ghost" size="icon" asChild>
                          <Link to={`/invoices/${invoice.id}`}>
                            <Eye className="h-4 w-4" />
                          </Link>
                        </Button>
                        {canCreate && invoice.status !== 'paid' && invoice.status !== 'cancelled' && (
                          <Button variant="ghost" size="icon" asChild>
                            <Link to={`/invoices/${invoice.id}/edit`}>
                              <Edit className="h-4 w-4" />
                            </Link>
                          </Button>
                        )}
                        {canCreate && invoice.status !== 'archived' && (
                          <Button 
                            variant="ghost" 
                            size="icon"
                            onClick={() => handleArchive(invoice.id)}
                            title="Archive invoice"
                          >
                            <Archive className="h-4 w-4" />
                          </Button>
                        )}
                        {canCreate && invoice.status === 'archived' && (
                          <Button 
                            variant="ghost" 
                            size="icon"
                            onClick={() => handleUnarchive(invoice.id)}
                            title="Unarchive invoice"
                          >
                            <ArchiveRestore className="h-4 w-4" />
                          </Button>
                        )}
                        {canCreate && (
                          <Button 
                            variant="ghost" 
                            size="icon"
                            onClick={() => handleDelete(invoice.id)}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        )}
                        <Button 
                          variant="ghost" 
                          size="icon"
                          onClick={async () => {
                            try {
                              await invoicesApi.download(invoice.id);
                              toast.success('Invoice downloaded');
                            } catch (error: any) {
                              toast.error(error?.message || 'Failed to download invoice');
                            }
                          }}
                        >
                          <Download className="h-4 w-4" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
