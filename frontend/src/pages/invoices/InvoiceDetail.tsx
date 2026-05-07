import { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { LoadingSpinner } from '@/components/LoadingSpinner';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { 
  Select, 
  SelectContent, 
  SelectItem, 
  SelectTrigger, 
  SelectValue 
} from '@/components/ui/select';
import { format } from 'date-fns';
import { 
  ArrowLeft, Download, Edit, CreditCard, CheckCircle, 
  Building2, User, Calendar, X, Star, Archive, ArchiveRestore
} from 'lucide-react';
import { toast } from 'sonner';
import { invoicesApi } from '@/lib/api/invoices';
import type { Invoice } from '@/lib/api/invoices';
import { ratingsApi, type ServiceProviderRating } from '@/lib/api/ratings';
import { RatingForm } from '@/components/RatingForm';
import { RatingStars } from '@/components/RatingStars';
import { installmentPlansApi, type InstallmentPlan, type InstallmentPayment } from '@/lib/api/installmentPlans';
import { paymentMethodsApi, type PaymentMethod } from '@/lib/api/payment-methods';
import { Progress } from '@/components/ui/progress';
import { CheckCircle2, Clock, AlertCircle, Plus, Zap, ZapOff } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { useAuth } from '@/contexts/AuthContext';
import { getUserRole } from '@/lib/utils/user';

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isCancelDialogOpen, setIsCancelDialogOpen] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [isRatingDialogOpen, setIsRatingDialogOpen] = useState(false);
  const [existingRating, setExistingRating] = useState<ServiceProviderRating | null>(null);
  const [loadingRating, setLoadingRating] = useState(false);
  const [installmentPlan, setInstallmentPlan] = useState<InstallmentPlan | null>(null);
  const [loadingPlan, setLoadingPlan] = useState(false);
  const [isCreatePlanDialogOpen, setIsCreatePlanDialogOpen] = useState(false);
  const [creatingPlan, setCreatingPlan] = useState(false);
  const [planFormData, setPlanFormData] = useState({
    plan_name: '',
    total_installments: 3,
    installment_amount: '',
    frequency: 'monthly' as 'daily' | 'weekly' | 'biweekly' | 'monthly' | 'quarterly' | 'custom',
    frequency_days: 30,
    start_date: format(new Date(), 'yyyy-MM-dd'),
    payment_method_id: '',
    auto_charge: false,
    notes: '',
  });
  const { user } = useAuth();
  const userRole = getUserRole(user) || 'customer';
  const isCustomer = userRole === 'customer';
  const isAdminOrServiceProvider = userRole === 'admin' || userRole === 'service_provider';
  const [isDisputeDialogOpen, setIsDisputeDialogOpen] = useState(false);
  const [disputeReason, setDisputeReason] = useState('');
  const [disputing, setDisputing] = useState(false);
  const [isDisputeResolutionDialogOpen, setIsDisputeResolutionDialogOpen] = useState(false);
  const [disputeResolution, setDisputeResolution] = useState('');
  const [resolvingDispute, setResolvingDispute] = useState(false);
  const [disputeAction, setDisputeAction] = useState<'resolve' | 'reject' | null>(null);
  const canCreateInstallmentPlan = userRole === 'admin' || userRole === 'service_provider';
  const [paymentMethods, setPaymentMethods] = useState<PaymentMethod[]>([]);
  const [loadingPaymentMethods, setLoadingPaymentMethods] = useState(false);
  const [isAutoPayDialogOpen, setIsAutoPayDialogOpen] = useState(false);
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<string>('');
  const [togglingAutoPay, setTogglingAutoPay] = useState(false);

  useEffect(() => {
    let isCancelled = false;

    const loadInvoice = async () => {
      if (!id) {
        setError('Invoice ID not found');
        setLoading(false);
        return;
      }

      const invoiceId = Number(id);
      if (isNaN(invoiceId)) {
        setError('Invalid invoice ID');
        setLoading(false);
        return;
      }

      try {
        setLoading(true);
        setError(null);
        const response = await invoicesApi.getById(invoiceId);
        
        if (isCancelled) return;
        
        if (response.data) {
          setInvoice(response.data);
        } else {
          setError(response.message || 'Invoice not found');
        }
      } catch (err: any) {
        if (isCancelled) return;
        console.error('Error loading invoice:', err);
        const errorMessage = err?.message || 'Failed to load invoice';
        setError(errorMessage);
        toast.error(errorMessage);
      } finally {
        if (!isCancelled) {
          setLoading(false);
        }
      }
    };

    loadInvoice();

    // Refresh invoice when page becomes visible (e.g., after returning from payment page)
    const handleVisibilityChange = () => {
      if (!document.hidden && id && !isCancelled) {
        // Refresh invoice data when page becomes visible
        loadInvoice();
      }
    };

    const handleFocus = () => {
      if (id && !isCancelled) {
        // Refresh invoice data when window regains focus
        loadInvoice();
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', handleFocus);

    return () => {
      isCancelled = true;
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      window.removeEventListener('focus', handleFocus);
    };
  }, [id]);

  // Auto-refresh for pending invoices (poll every 5 seconds)
  useEffect(() => {
    // Only auto-refresh if invoice is pending or partially paid
    if (!invoice || !['pending', 'overdue'].includes(invoice.status || '')) {
      return;
    }

    const interval = setInterval(async () => {
      if (!id) return;

      try {
        const response = await invoicesApi.getById(Number(id));
        if (response.data) {
          setInvoice(response.data);
          // Stop polling if invoice is now paid
          if (response.data.status === 'paid') {
            clearInterval(interval);
          }
        }
      } catch (err: any) {
        console.error('Error refreshing invoice:', err);
      }
    }, 5000); // Refresh every 5 seconds

    return () => clearInterval(interval);
  }, [id, invoice?.status]);

  // Load existing rating for this invoice
  useEffect(() => {
    const loadRating = async () => {
      if (!id || !invoice) return;

      try {
        setLoadingRating(true);
        const response = await ratingsApi.getByInvoice(Number(id));
        // Backend returns { rating: {...} }, so extract the rating object
        const ratingData = (response.data as any)?.rating || response.data;
        if (ratingData && typeof ratingData === 'object' && ratingData.id && typeof ratingData.rating === 'number') {
          setExistingRating(ratingData);
        } else {
          setExistingRating(null);
        }
      } catch (error: any) {
        // Rating doesn't exist yet, that's fine
        console.log('No existing rating found:', error?.message);
        setExistingRating(null);
      } finally {
        setLoadingRating(false);
      }
    };

    if (invoice && invoice.status === 'paid') {
      loadRating();
    }
  }, [id, invoice]);

  // Load installment plan if invoice has one
  useEffect(() => {
    let isCancelled = false;
    
    const loadInstallmentPlan = async () => {
      if (!id || !invoice) return;
      
      try {
        setLoadingPlan(true);
        
        // Check if installment plan is already included in invoice data
        // Backend only includes it if the invoice has a plan, so if it's missing,
        // we know the invoice doesn't have one - no need to make an additional request
        if (invoice.installment_plan) {
          if (isCancelled) return;
          
          const plan = invoice.installment_plan;
          setInstallmentPlan(plan);
        } else {
          // Invoice doesn't have an installment plan (backend only includes it if exists)
          if (isCancelled) return;
          setInstallmentPlan(null);
        }
      } catch (err: any) {
        if (isCancelled) return;
        
        // getByInvoice should handle 404s and return null, but if an error is thrown
        // check if it's a 404 before logging
        const errorMessage = String(err?.message || '').toLowerCase();
        const isNotFoundError = errorMessage.includes('no installment plan found') || 
                                errorMessage.includes('not found') || 
                                errorMessage.includes('404') ||
                                err?.response?.status === 404;
        
        // Only log unexpected errors (not 404s)
        if (!isNotFoundError) {
          console.error('Error loading installment plan:', err);
        }
        setInstallmentPlan(null);
      } finally {
        if (!isCancelled) {
          setLoadingPlan(false);
        }
      }
    };

    if (invoice) {
      loadInstallmentPlan();
    }
    
    return () => {
      isCancelled = true;
    };
  }, [id, invoice]);

  const handleCreateInstallmentPlan = async () => {
    if (!id || !invoice) {
      toast.error('Invoice not found');
      return;
    }

    // Validate form
    if (planFormData.total_installments < 2 || planFormData.total_installments > 60) {
      toast.error('Total installments must be between 2 and 60');
      return;
    }

    if (planFormData.frequency === 'custom' && (!planFormData.frequency_days || planFormData.frequency_days < 1 || planFormData.frequency_days > 365)) {
      toast.error('Custom frequency days must be between 1 and 365');
      return;
    }

    try {
      setCreatingPlan(true);
      const data: any = {
        total_installments: planFormData.total_installments,
        frequency: planFormData.frequency,
        start_date: planFormData.start_date,
        auto_charge: planFormData.auto_charge,
      };

      if (planFormData.plan_name) {
        data.plan_name = planFormData.plan_name;
      }

      if (planFormData.installment_amount) {
        data.installment_amount = parseFloat(planFormData.installment_amount);
      }

      if (planFormData.frequency === 'custom') {
        data.frequency_days = planFormData.frequency_days;
      }

      if (planFormData.payment_method_id) {
        data.payment_method_id = parseInt(planFormData.payment_method_id);
      }

      if (planFormData.notes) {
        data.notes = planFormData.notes;
      }

      const plan = await installmentPlansApi.create(Number(id), data);
      if (plan) {
        setInstallmentPlan(plan);
        setIsCreatePlanDialogOpen(false);
        toast.success('Installment plan created successfully');
      }
      
      // Reset form
      setPlanFormData({
        plan_name: '',
        total_installments: 3,
        installment_amount: '',
        frequency: 'monthly',
        frequency_days: 30,
        start_date: format(new Date(), 'yyyy-MM-dd'),
        payment_method_id: '',
        auto_charge: false,
        notes: '',
      });
    } catch (error: any) {
      console.error('Error creating installment plan:', error);
      toast.error(error?.response?.data?.message || error?.message || 'Failed to create installment plan');
    } finally {
      setCreatingPlan(false);
    }
  };

  const handleDownload = async () => {
    if (!id) {
      toast.error('Invoice ID not found');
      return;
    }
    
    try {
      const invoiceId = Number(id);
      toast.loading('Preparing PDF download...', { id: 'download' });
      await invoicesApi.download(invoiceId);
      toast.success('Invoice PDF downloaded!', { id: 'download' });
    } catch (error: any) {
      console.error('Download error:', error);
      let errorMessage = 'Failed to download invoice PDF';
      
      if (error?.message) {
        if (error.message.includes('Not Found') || error.message.includes('404')) {
          errorMessage = 'Invoice not found. Please make sure the invoice exists in the database.';
        } else if (error.message.includes('Unauthorized') || error.message.includes('403')) {
          errorMessage = 'You do not have permission to download this invoice.';
        } else {
          errorMessage = error.message;
        }
      }
      
      toast.error(errorMessage, { id: 'download' });
    }
  };

  const handleMarkAsPaid = async () => {
    if (!id) return;
    
    try {
      const invoiceId = Number(id);
      const response = await invoicesApi.markAsPaid(invoiceId);
      
      if (response.data) {
        setInvoice(response.data);
        toast.success('Invoice marked as paid!');
      } else {
        toast.error(response.message || 'Failed to mark invoice as paid');
      }
    } catch (error: any) {
      console.error('Error marking invoice as paid:', error);
      toast.error(error?.message || 'Failed to mark invoice as paid');
    }
  };

  const handleCancel = async () => {
    if (!id) {
      toast.error('Invoice ID not found');
      return;
    }

    try {
      setCancelling(true);
      const invoiceId = Number(id);
      const response = await invoicesApi.cancel(invoiceId, cancelReason || undefined);
      
      if (response.data) {
        toast.success('Invoice cancelled successfully!');
        setIsCancelDialogOpen(false);
        setCancelReason('');
        // Reload invoice to get updated status
        const updatedResponse = await invoicesApi.getById(invoiceId);
        if (updatedResponse.data) {
          setInvoice(updatedResponse.data);
        }
      } else {
        toast.error(response.message || 'Failed to cancel invoice');
      }
    } catch (error: any) {
      console.error('Error cancelling invoice:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to cancel invoice';
      toast.error(errorMessage);
    } finally {
      setCancelling(false);
    }
  };

  const handleDispute = async () => {
    if (!id || !disputeReason.trim()) {
      toast.error('Please provide a reason for disputing this invoice');
      return;
    }

    if (disputeReason.trim().length < 10) {
      toast.error('Dispute reason must be at least 10 characters long');
      return;
    }

    try {
      setDisputing(true);
      const invoiceId = Number(id);
      const response = await invoicesApi.dispute(invoiceId, disputeReason.trim());
      
      if (response.data) {
        toast.success('Invoice disputed successfully!');
        setIsDisputeDialogOpen(false);
        setDisputeReason('');
        setInvoice(response.data);
      } else {
        toast.error(response.message || 'Failed to dispute invoice');
      }
    } catch (error: any) {
      console.error('Error disputing invoice:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to dispute invoice';
      toast.error(errorMessage);
    } finally {
      setDisputing(false);
    }
  };

  const handleMarkDisputeUnderReview = async () => {
    if (!id) return;

    try {
      const invoiceId = Number(id);
      const response = await invoicesApi.markDisputeUnderReview(invoiceId);
      
      if (response.data) {
        toast.success('Dispute marked as under review');
        setInvoice(response.data);
      } else {
        toast.error(response.message || 'Failed to update dispute status');
      }
    } catch (error: any) {
      console.error('Error updating dispute status:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to update dispute status';
      toast.error(errorMessage);
    }
  };

  const handleResolveOrRejectDispute = async () => {
    if (!id || !disputeResolution.trim() || !disputeAction) {
      toast.error('Please provide a resolution note');
      return;
    }

    if (disputeResolution.trim().length < 10) {
      toast.error('Resolution note must be at least 10 characters long');
      return;
    }

    try {
      setResolvingDispute(true);
      const invoiceId = Number(id);
      const response = disputeAction === 'resolve' 
        ? await invoicesApi.resolveDispute(invoiceId, disputeResolution.trim())
        : await invoicesApi.rejectDispute(invoiceId, disputeResolution.trim());
      
      if (response.data) {
        toast.success(`Dispute ${disputeAction === 'resolve' ? 'resolved' : 'rejected'} successfully!`);
        setIsDisputeResolutionDialogOpen(false);
        setDisputeResolution('');
        setDisputeAction(null);
        setInvoice(response.data);
      } else {
        toast.error(response.message || `Failed to ${disputeAction === 'resolve' ? 'resolve' : 'reject'} dispute`);
      }
    } catch (error: any) {
      console.error(`Error ${disputeAction === 'resolve' ? 'resolving' : 'rejecting'} dispute:`, error);
      const errorMessage = error?.response?.data?.message || error?.message || `Failed to ${disputeAction === 'resolve' ? 'resolve' : 'reject'} dispute`;
      toast.error(errorMessage);
    } finally {
      setResolvingDispute(false);
    }
  };

  const handleArchive = async () => {
    if (!invoice) return;
    
    if (!confirm('Are you sure you want to archive this invoice?')) {
      return;
    }

    try {
      const response = await invoicesApi.archive(invoice.id);
      if (response.data) {
        toast.success('Invoice archived successfully');
        setInvoice(response.data);
      } else {
        toast.error(response.message || 'Failed to archive invoice');
      }
    } catch (error: any) {
      console.error('Error archiving invoice:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to archive invoice';
      toast.error(errorMessage);
    }
  };

  const handleUnarchive = async () => {
    if (!invoice) return;

    try {
      const response = await invoicesApi.unarchive(invoice.id);
      if (response.data) {
        toast.success('Invoice unarchived successfully');
        setInvoice(response.data);
      } else {
        toast.error(response.message || 'Failed to unarchive invoice');
      }
    } catch (error: any) {
      console.error('Error unarchiving invoice:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to unarchive invoice';
      toast.error(errorMessage);
    }
  };

  // Load payment methods for auto-pay
  useEffect(() => {
    const loadPaymentMethods = async () => {
      if (!invoice || !invoice.is_recurring) return;
      
      try {
        setLoadingPaymentMethods(true);
        const response = await paymentMethodsApi.getAll();
        if (response.data) {
          setPaymentMethods(Array.isArray(response.data) ? response.data : []);
        }
      } catch (error: any) {
        console.error('Error loading payment methods:', error);
      } finally {
        setLoadingPaymentMethods(false);
      }
    };

    if (invoice?.is_recurring && user && (user.role === 'customer' || (user as any).roles?.some((r: any) => r.name === 'customer'))) {
      loadPaymentMethods();
    }
  }, [invoice?.id, invoice?.is_recurring, user]);

  const handleEnableAutoPay = async () => {
    if (!invoice || !selectedPaymentMethod) {
      toast.error('Please select a payment method');
      return;
    }

    try {
      setTogglingAutoPay(true);
      const response = await invoicesApi.enableAutoPay(invoice.id, Number(selectedPaymentMethod));
      if (response.data) {
        toast.success('Auto-pay enabled successfully');
        setInvoice(response.data);
        setIsAutoPayDialogOpen(false);
        setSelectedPaymentMethod('');
      } else {
        toast.error(response.message || 'Failed to enable auto-pay');
      }
    } catch (error: any) {
      console.error('Error enabling auto-pay:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to enable auto-pay';
      toast.error(errorMessage);
    } finally {
      setTogglingAutoPay(false);
    }
  };

  const handleDisableAutoPay = async () => {
    if (!invoice) return;

    if (!confirm('Are you sure you want to disable auto-pay for this invoice?')) {
      return;
    }

    try {
      setTogglingAutoPay(true);
      const response = await invoicesApi.disableAutoPay(invoice.id);
      if (response.data) {
        toast.success('Auto-pay disabled successfully');
        setInvoice(response.data);
      } else {
        toast.error(response.message || 'Failed to disable auto-pay');
      }
    } catch (error: any) {
      console.error('Error disabling auto-pay:', error);
      const errorMessage = error?.response?.data?.message || error?.message || 'Failed to disable auto-pay';
      toast.error(errorMessage);
    } finally {
      setTogglingAutoPay(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner />
      </div>
    );
  }

  if (error || !invoice) {
    return (
      <div className="flex flex-col items-center justify-center py-12">
        <h2 className="text-xl font-semibold mb-2">Invoice not found</h2>
        <p className="text-muted-foreground mb-4">
          {error || "The invoice you're looking for doesn't exist."}
        </p>
        <Button onClick={() => navigate('/invoices')}>Go back to invoices</Button>
      </div>
    );
  }

  const payments = invoice.payments || [];
  const canCancel = invoice.status !== 'paid' && invoice.status !== 'cancelled' && invoice.status !== 'archived';
  // Only service providers and admins can edit invoices
  const canEdit = invoice.status !== 'paid' && invoice.status !== 'cancelled' && invoice.status !== 'archived' && (userRole === 'admin' || userRole === 'service_provider');
  const isArchived = invoice.status === 'archived';
  
  // Calculate payment totals
  const totalPaid = invoice.total_paid ?? payments.reduce((sum: number, p: any) => {
    return sum + (p.status === 'completed' ? (parseFloat(p.amount) || 0) : 0);
  }, 0);
  const remainingAmount = invoice.remaining_amount ?? (invoice.total_amount - totalPaid);
  const paymentProgress = invoice.payment_progress ?? (invoice.total_amount > 0 ? (totalPaid / invoice.total_amount) * 100 : 0);
  const hasPartialPayments = payments.some((p: any) => p.payment_type === 'partial');

  return (
    <div className="space-y-6 animate-fade-in max-w-4xl">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold">{invoice.invoice_number}</h1>
              <StatusBadge status={invoice.display_status || invoice.status} size="lg" />
            </div>
            <p className="text-muted-foreground mt-1">{invoice.title}</p>
          </div>
        </div>
        <div className="flex gap-3 ml-12 sm:ml-0">
          <Button variant="outline" onClick={handleDownload}>
            <Download className="h-4 w-4 mr-2" />
            Download PDF
          </Button>
          {!isArchived && (
            <>
              {/* Edit and Pay buttons for pending/overdue invoices */}
              {canEdit && (
              <Button variant="outline" asChild>
                <Link to={`/invoices/${id}/edit`}>
                  <Edit className="h-4 w-4 mr-2" />
                  Edit
                </Link>
              </Button>
              )}
              {invoice.status === 'pending' && (
              <Button asChild>
                <Link to={`/payments/create?invoice=${id}`}>
                  <CreditCard className="h-4 w-4 mr-2" />
                  Pay Now
                </Link>
              </Button>
              )}
              {canCancel && (
                <Button 
                  variant="destructive" 
                  onClick={() => setIsCancelDialogOpen(true)}
                >
                  <X className="h-4 w-4 mr-2" />
                  Cancel Invoice
                </Button>
              )}
              {/* Archive button for paid or cancelled invoices */}
              {(invoice.status === 'paid' || invoice.status === 'cancelled') && (
                <Button 
                  variant="outline" 
                  onClick={handleArchive}
                >
                  <Archive className="h-4 w-4 mr-2" />
                  Archive
                </Button>
              )}
            </>
          )}
          {isArchived && (
            <Button 
              variant="outline" 
              onClick={handleUnarchive}
            >
              <ArchiveRestore className="h-4 w-4 mr-2" />
              Unarchive
            </Button>
          )}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-6">
          <div className="rounded-xl border border-border bg-card p-8">
            <div className="flex justify-between items-start mb-8">
              <div>
                <div className="h-12 w-12 rounded-xl bg-primary flex items-center justify-center mb-4">
                  <span className="text-primary-foreground font-bold text-lg">eB</span>
                </div>
                <h2 className="text-xl font-bold">eBill Platform</h2>
                <p className="text-sm text-muted-foreground">contact@ebill.com</p>
              </div>
              <div className="text-right">
                <h3 className="text-2xl font-bold">INVOICE</h3>
                <p className="text-muted-foreground">{invoice.invoice_number}</p>
              </div>
            </div>

            <div className="grid sm:grid-cols-2 gap-6 mb-8">
              <div>
                <p className="text-sm text-muted-foreground mb-2">Bill From</p>
                <div className="flex items-start gap-3">
                  <Building2 className="h-5 w-5 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="font-medium">
                      {invoice.service_provider?.name || 
                       invoice.service_provider?.company_name || 
                       'Service Provider'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {invoice.service_provider?.email || 'N/A'}
                    </p>
                  </div>
                </div>
              </div>
              <div>
                <p className="text-sm text-muted-foreground mb-2">Bill To</p>
                <div className="flex items-start gap-3">
                  <User className="h-5 w-5 text-muted-foreground mt-0.5" />
                  <div>
                    <p className="font-medium">{invoice.user?.name || 'Customer'}</p>
                    <p className="text-sm text-muted-foreground">{invoice.user?.email || 'N/A'}</p>
                  </div>
                </div>
              </div>
            </div>

            <div className="grid sm:grid-cols-2 gap-4 mb-8">
              <div className="flex items-center gap-3">
                <Calendar className="h-5 w-5 text-muted-foreground" />
                <div>
                  <p className="text-sm text-muted-foreground">Issue Date</p>
                  <p className="font-medium">
                    {invoice.issue_date ? format(new Date(invoice.issue_date), 'MMMM d, yyyy') : 'N/A'}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Calendar className="h-5 w-5 text-muted-foreground" />
                <div>
                  <p className="text-sm text-muted-foreground">Due Date</p>
                  <p className="font-medium">
                    {invoice.due_date ? format(new Date(invoice.due_date), 'MMMM d, yyyy') : 'N/A'}
                  </p>
                </div>
              </div>
            </div>

            {invoice.description && (
              <div className="mb-8">
                <p className="text-sm text-muted-foreground mb-2">Description</p>
                <p>{invoice.description}</p>
              </div>
            )}

            <div className="border-t border-border pt-6">
              <div className="flex justify-between mb-2">
                <span className="text-muted-foreground">Subtotal</span>
                <span>${Number(invoice.amount ?? 0).toLocaleString()}</span>
              </div>
              {(invoice.tax_amount || 0) > 0 && (
                <div className="flex justify-between mb-2">
                <span className="text-muted-foreground">Tax</span>
                  <span>${Number(invoice.tax_amount ?? 0).toLocaleString()}</span>
              </div>
              )}
              {(invoice.fee_amount || 0) > 0 && (
                <div className="flex justify-between mb-2">
                  <span className="text-muted-foreground">Fee</span>
                  <span>${Number(invoice.fee_amount ?? 0).toLocaleString()}</span>
                </div>
              )}
              <div className="flex justify-between pt-4 border-t border-border">
                <span className="text-lg font-semibold">Total</span>
                <span className="text-2xl font-bold">${Number(invoice.total_amount ?? 0).toLocaleString()}</span>
              </div>
            </div>
          </div>
        </div>

        <div className="space-y-6">
          {/* Dispute Section */}
          {invoice.dispute_status && invoice.dispute_status !== 'none' && (
            <div className={`rounded-xl border border-border bg-card p-6 ${
              invoice.dispute_status === 'pending' || invoice.dispute_status === 'under_review' 
                ? 'border-warning bg-warning/5' 
                : invoice.dispute_status === 'resolved' 
                ? 'border-success bg-success/5' 
                : 'border-error bg-error/5'
            }`}>
              <div className="flex items-center justify-between mb-4">
                <h3 className="font-semibold flex items-center gap-2">
                  <AlertCircle className={`h-5 w-5 ${
                    invoice.dispute_status === 'pending' || invoice.dispute_status === 'under_review'
                      ? 'text-warning'
                      : invoice.dispute_status === 'resolved'
                      ? 'text-success'
                      : 'text-error'
                  }`} />
                  Invoice Dispute
                </h3>
                <StatusBadge 
                  status={
                    invoice.dispute_status === 'pending' ? 'pending' :
                    invoice.dispute_status === 'under_review' ? 'pending' :
                    invoice.dispute_status === 'resolved' ? 'paid' :
                    'cancelled'
                  }
                />
              </div>
              
              <div className="space-y-3">
                <div>
                  <p className="text-sm font-medium mb-1">Dispute Reason</p>
                  <p className="text-sm text-muted-foreground">{invoice.dispute_reason}</p>
                </div>
                
                {invoice.disputed_at && (
                  <div>
                    <p className="text-sm font-medium mb-1">Disputed On</p>
                    <p className="text-sm text-muted-foreground">
                      {format(new Date(invoice.disputed_at), 'MMMM d, yyyy h:mm a')}
                    </p>
                  </div>
                )}

                {invoice.dispute_resolution && (
                  <div>
                    <p className="text-sm font-medium mb-1">
                      {invoice.dispute_status === 'resolved' ? 'Resolution' : 'Rejection Reason'}
                    </p>
                    <p className="text-sm text-muted-foreground">{invoice.dispute_resolution}</p>
                  </div>
                )}

                {invoice.dispute_resolved_at && invoice.dispute_resolver && (
                  <div>
                    <p className="text-sm font-medium mb-1">
                      {invoice.dispute_status === 'resolved' ? 'Resolved' : 'Rejected'} By
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {invoice.dispute_resolver?.name || 'Admin'} on{' '}
                      {format(new Date(invoice.dispute_resolved_at), 'MMMM d, yyyy h:mm a')}
                    </p>
                  </div>
                )}

                {isAdminOrServiceProvider && 
                 (invoice.dispute_status === 'pending' || invoice.dispute_status === 'under_review') && (
                  <div className="flex gap-2 pt-3 border-t border-border">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={handleMarkDisputeUnderReview}
                      disabled={invoice.dispute_status === 'under_review'}
                    >
                      Mark as Under Review
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setDisputeAction('resolve');
                        setIsDisputeResolutionDialogOpen(true);
                      }}
                    >
                      Resolve
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={() => {
                        setDisputeAction('reject');
                        setIsDisputeResolutionDialogOpen(true);
                      }}
                    >
                      Reject
                    </Button>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Dispute Button for Customers */}
          {isCustomer && 
           invoice.dispute_status === 'none' && 
           invoice.status !== 'paid' && 
           invoice.status !== 'cancelled' && 
           invoice.status !== 'archived' && (
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="font-semibold mb-4">Dispute Invoice</h3>
              <p className="text-sm text-muted-foreground mb-4">
                If you believe there is an error with this invoice, you can dispute it.
              </p>
              <Button
                variant="outline"
                className="w-full"
                onClick={() => setIsDisputeDialogOpen(true)}
              >
                <AlertCircle className="h-4 w-4 mr-2" />
                Dispute Invoice
              </Button>
            </div>
          )}

          {invoice.status !== 'paid' && (
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="font-semibold mb-4">Quick Actions</h3>
              <div className="space-y-3">
                <Button className="w-full" onClick={handleMarkAsPaid}>
                  <CheckCircle className="h-4 w-4 mr-2" />
                  Mark as Paid
                </Button>
              </div>
            </div>
          )}

          {!installmentPlan && invoice && invoice.status !== 'paid' && invoice.status !== 'cancelled' && invoice.status !== 'archived' && canCreateInstallmentPlan && (
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="font-semibold mb-4">Installment Plan</h3>
              <p className="text-sm text-muted-foreground mb-4">
                Create an installment plan to allow customers to pay this invoice in multiple installments.
              </p>
              <Button
                variant="outline"
                className="w-full"
                onClick={() => setIsCreatePlanDialogOpen(true)}
              >
                <Plus className="h-4 w-4 mr-2" />
                Create Installment Plan
              </Button>
            </div>
          )}

          {installmentPlan && (
            <div className="rounded-xl border border-border bg-card p-6">
              <div className="flex items-center justify-between mb-4">
                <h3 className="font-semibold">Installment Plan</h3>
              </div>
              <div className="space-y-4">
                <div>
                  <div className="flex justify-between items-center mb-2">
                    <span className="text-sm text-muted-foreground">Progress</span>
                    <span className="text-sm font-medium">
                      {installmentPlan.paid_installments_count || 0} / {installmentPlan.total_installments} installments
                    </span>
                  </div>
                  <Progress value={installmentPlan.progress_percentage || 0} className="h-2" />
                </div>
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span className="text-muted-foreground">Total Paid</span>
                    <p className="font-semibold">${Number(installmentPlan.total_paid ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Remaining</span>
                    <p className="font-semibold">${Number(installmentPlan.remaining_amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                  </div>
                </div>
                <div className="space-y-2 max-h-64 overflow-y-auto">
                  {installmentPlan.installment_payments
                    ?.slice()
                    .sort((a: InstallmentPayment, b: InstallmentPayment) => {
                      // Sort by due_date first, then by installment_number
                      const dateDiff = new Date(a.due_date).getTime() - new Date(b.due_date).getTime();
                      return dateDiff !== 0 ? dateDiff : a.installment_number - b.installment_number;
                    })
                    .map((installment: InstallmentPayment) => (
                    <div
                      key={installment.id}
                      className={`flex items-center justify-between p-3 rounded-lg border ${
                        installment.status === 'paid'
                          ? 'bg-success-light/10 border-success-light'
                          : installment.status === 'overdue'
                          ? 'bg-error-light/10 border-error-light'
                          : 'bg-muted/50 border-border'
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        {installment.status === 'paid' ? (
                          <CheckCircle2 className="h-5 w-5 text-success" />
                        ) : installment.status === 'overdue' ? (
                          <AlertCircle className="h-5 w-5 text-error" />
                        ) : (
                          <Clock className="h-5 w-5 text-muted-foreground" />
                        )}
                        <div>
                          <p className="font-medium text-sm">
                            Installment #{installment.installment_number}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            Due: {format(new Date(installment.due_date), 'MMM d, yyyy')}
                          </p>
                        </div>
                      </div>
                      <div className="text-right">
                        <p className="font-semibold">${Number(installment.amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                        {installment.status === 'pending' && (
                          <Button
                            size="sm"
                            variant="outline"
                            className="mt-1"
                            asChild
                          >
                            <Link to={`/payments/create?invoice=${id}&installment=${installment.id}`}>
                              Pay
                            </Link>
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* Auto-Pay Section */}
          {invoice?.is_recurring && user && (user.role === 'customer' || (user as any).roles?.some((r: any) => r.name === 'customer')) && (
            <div className="rounded-xl border border-border bg-card p-6">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2">
                  {invoice.auto_pay_enabled ? (
                    <Zap className="h-5 w-5 text-success" />
                  ) : (
                    <ZapOff className="h-5 w-5 text-muted-foreground" />
                  )}
                  <h3 className="font-semibold">Auto-Pay</h3>
                </div>
                {invoice.auto_pay_enabled ? (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={handleDisableAutoPay}
                    disabled={togglingAutoPay}
                  >
                    {togglingAutoPay ? 'Disabling...' : 'Disable Auto-Pay'}
                  </Button>
                ) : (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setIsAutoPayDialogOpen(true)}
                    disabled={loadingPaymentMethods || paymentMethods.length === 0}
                  >
                    Enable Auto-Pay
                  </Button>
                )}
              </div>

              {invoice.auto_pay_enabled ? (
                <div className="space-y-3">
                  <div className="flex items-center gap-2 text-sm">
                    <CheckCircle2 className="h-4 w-4 text-success" />
                    <span className="text-success font-medium">Auto-pay is enabled</span>
                  </div>
                  {invoice.auto_pay_payment_method && (
                    <div className="text-sm">
                      <span className="text-muted-foreground">Payment Method: </span>
                      <span className="font-medium">
                        {invoice.auto_pay_payment_method.name || invoice.auto_pay_payment_method.type}
                        {invoice.auto_pay_payment_method.last_four && ` •••• ${invoice.auto_pay_payment_method.last_four}`}
                      </span>
                    </div>
                  )}
                  {invoice.last_auto_pay_attempt && (
                    <div className="text-sm">
                      <span className="text-muted-foreground">Last Attempt: </span>
                      <span className="font-medium">
                        {format(new Date(invoice.last_auto_pay_attempt), 'MMM d, yyyy h:mm a')}
                      </span>
                    </div>
                  )}
                  {invoice.auto_pay_failure_reason && (
                    <div className="text-sm text-error bg-error-light/10 p-2 rounded">
                      <span className="font-medium">Last Failure: </span>
                      {invoice.auto_pay_failure_reason}
                    </div>
                  )}
                  <p className="text-xs text-muted-foreground">
                    This invoice will be automatically paid when it becomes due using the selected payment method.
                  </p>
                </div>
              ) : (
                <div className="space-y-2">
                  <p className="text-sm text-muted-foreground">
                    Enable auto-pay to automatically pay this recurring invoice when it becomes due.
                  </p>
                  {loadingPaymentMethods ? (
                    <p className="text-xs text-muted-foreground">Loading payment methods...</p>
                  ) : paymentMethods.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                      No active payment methods found. Please add a payment method first.
                    </p>
                  ) : null}
                </div>
              )}
            </div>
          )}

          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="font-semibold mb-4">Payment History</h3>
            {payments.length === 0 ? (
              <p className="text-sm text-muted-foreground">No payments yet</p>
            ) : (
              <div className="space-y-3">
                {payments.map((payment: any) => {
                  const paymentDate = payment.created_at || payment.createdAt;
                  return (
                  <div key={payment.id} className="flex items-center justify-between">
                    <div>
                        <p className="font-medium text-sm">{payment.payment_reference || payment.reference || `Payment #${payment.id}`}</p>
                      <p className="text-xs text-muted-foreground">
                          {paymentDate ? format(new Date(paymentDate), 'MMM d, yyyy') : 'N/A'}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="font-semibold text-success">
                          +${Number(payment.amount ?? 0).toLocaleString()}
                      </p>
                        <StatusBadge status={payment.status || 'completed'} size="sm" />
                    </div>
                  </div>
                  );
                })}
              </div>
            )}
          </div>

          <div className="rounded-xl border border-border bg-card p-6">
            <h3 className="font-semibold mb-4">Invoice Information</h3>
            <div className="space-y-3 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Status</span>
                <StatusBadge status={invoice.display_status || invoice.status} size="sm" />
        </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Invoice Number</span>
                <span className="font-mono">{invoice.invoice_number}</span>
      </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Created</span>
                <span>{format(new Date(invoice.created_at), 'MMM d, yyyy')}</span>
              </div>
              {invoice.paid_date && (
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Paid Date</span>
                  <span>{format(new Date(invoice.paid_date), 'MMM d, yyyy')}</span>
                </div>
              )}
            </div>
          </div>

          {invoice.status === 'paid' && invoice.service_provider_id && (
            <div className="rounded-xl border border-border bg-card p-6">
              <h3 className="font-semibold mb-4">Rate Service Provider</h3>
              {loadingRating ? (
                <div className="flex items-center justify-center py-4">
                  <LoadingSpinner size="sm" />
                </div>
              ) : existingRating && typeof existingRating.rating === 'number' ? (
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <RatingStars rating={existingRating.rating} size="md" />
                    <span className="text-sm text-muted-foreground">
                      You rated {String(existingRating.rating)} out of 5
                    </span>
                  </div>
                  {existingRating.comment && (
                    <p className="text-sm text-muted-foreground">{String(existingRating.comment)}</p>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setIsRatingDialogOpen(true)}
                  >
                    <Edit className="h-4 w-4 mr-2" />
                    Update Rating
                  </Button>
                </div>
              ) : (
                <div className="space-y-3">
                  <p className="text-sm text-muted-foreground">
                    Share your experience with this service provider
                  </p>
                  <Button
                    variant="outline"
                    onClick={() => setIsRatingDialogOpen(true)}
                  >
                    <Star className="h-4 w-4 mr-2" />
                    Rate Service Provider
                  </Button>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Rating Dialog */}
      <Dialog open={isRatingDialogOpen} onOpenChange={setIsRatingDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Rate Service Provider</DialogTitle>
            <DialogDescription>
              {existingRating
                ? 'Update your rating for this service provider'
                : 'Share your experience with this service provider'}
            </DialogDescription>
          </DialogHeader>
          {invoice && invoice.service_provider_id && (
            <RatingForm
              serviceProviderId={invoice.service_provider_id}
              invoiceId={invoice.id}
              existingRating={existingRating && existingRating.id ? {
                id: existingRating.id,
                rating: existingRating.rating,
                comment: existingRating.comment || undefined,
              } : undefined}
              onSuccess={() => {
                setIsRatingDialogOpen(false);
                // Reload rating
                const loadRating = async () => {
                  try {
                    const response = await ratingsApi.getByInvoice(Number(id));
                    // Backend returns { rating: {...} }, so extract the rating object
                    const ratingData = (response.data as any)?.rating || response.data;
                    if (ratingData && typeof ratingData === 'object' && ratingData.id && typeof ratingData.rating === 'number') {
                      setExistingRating(ratingData);
                    } else {
                      setExistingRating(null);
                    }
                  } catch (error: any) {
                    console.log('Error reloading rating:', error?.message);
                    setExistingRating(null);
                  }
                };
                loadRating();
              }}
              onCancel={() => setIsRatingDialogOpen(false)}
            />
          )}
        </DialogContent>
      </Dialog>

      {/* Cancel Invoice Dialog */}
      <AlertDialog open={isCancelDialogOpen} onOpenChange={setIsCancelDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Cancel Invoice</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to cancel this invoice? This action cannot be undone. 
              You can optionally provide a reason for cancellation.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="cancel-reason">Cancellation Reason (Optional)</Label>
              <Textarea
                id="cancel-reason"
                placeholder="Enter reason for cancellation..."
                value={cancelReason}
                onChange={(e) => setCancelReason(e.target.value)}
                rows={3}
                maxLength={500}
              />
              <p className="text-xs text-muted-foreground">
                {cancelReason.length}/500 characters
              </p>
            </div>
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={() => setCancelReason('')}>
              Keep Invoice
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={handleCancel}
              disabled={cancelling}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {cancelling ? 'Cancelling...' : 'Cancel Invoice'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Dispute Invoice Dialog */}
      <Dialog open={isDisputeDialogOpen} onOpenChange={setIsDisputeDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Dispute Invoice</DialogTitle>
            <DialogDescription>
              If you believe there is an error with this invoice, please provide a detailed reason for disputing it.
              Your dispute will be reviewed by the service provider or administrator.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="dispute-reason">Dispute Reason *</Label>
              <Textarea
                id="dispute-reason"
                placeholder="Please explain why you are disputing this invoice (minimum 10 characters)..."
                value={disputeReason}
                onChange={(e) => setDisputeReason(e.target.value)}
                rows={6}
                minLength={10}
                maxLength={2000}
                required
              />
              <p className="text-xs text-muted-foreground">
                {disputeReason.length}/2000 characters (minimum 10 required)
              </p>
            </div>
          </div>
          <div className="flex justify-end gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setIsDisputeDialogOpen(false);
                setDisputeReason('');
              }}
            >
              Cancel
            </Button>
            <Button
              onClick={handleDispute}
              disabled={disputing || disputeReason.trim().length < 10}
            >
              {disputing ? 'Submitting...' : 'Submit Dispute'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Dispute Resolution Dialog */}
      <Dialog open={isDisputeResolutionDialogOpen} onOpenChange={setIsDisputeResolutionDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {disputeAction === 'resolve' ? 'Resolve Dispute' : 'Reject Dispute'}
            </DialogTitle>
            <DialogDescription>
              {disputeAction === 'resolve' 
                ? 'Please provide a resolution note explaining how the dispute was resolved.'
                : 'Please provide a reason for rejecting this dispute.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="dispute-resolution">
                {disputeAction === 'resolve' ? 'Resolution Note *' : 'Rejection Reason *'}
              </Label>
              <Textarea
                id="dispute-resolution"
                placeholder={`Please provide ${disputeAction === 'resolve' ? 'a resolution note' : 'a rejection reason'} (minimum 10 characters)...`}
                value={disputeResolution}
                onChange={(e) => setDisputeResolution(e.target.value)}
                rows={6}
                minLength={10}
                maxLength={2000}
                required
              />
              <p className="text-xs text-muted-foreground">
                {disputeResolution.length}/2000 characters (minimum 10 required)
              </p>
            </div>
          </div>
          <div className="flex justify-end gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setIsDisputeResolutionDialogOpen(false);
                setDisputeResolution('');
                setDisputeAction(null);
              }}
            >
              Cancel
            </Button>
            <Button
              onClick={handleResolveOrRejectDispute}
              disabled={resolvingDispute || disputeResolution.trim().length < 10}
              variant={disputeAction === 'reject' ? 'destructive' : 'default'}
            >
              {resolvingDispute 
                ? `${disputeAction === 'resolve' ? 'Resolving' : 'Rejecting'}...` 
                : disputeAction === 'resolve' 
                  ? 'Resolve Dispute' 
                  : 'Reject Dispute'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Create Installment Plan Dialog */}
      <Dialog open={isCreatePlanDialogOpen} onOpenChange={setIsCreatePlanDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create Installment Plan</DialogTitle>
            <DialogDescription>
              Set up a payment plan to split this invoice into multiple installments.
            </DialogDescription>
          </DialogHeader>

          {invoice && (
            <div className="space-y-4 mt-4">
              <div className="p-4 rounded-lg bg-muted/50">
                <div className="flex justify-between text-sm mb-2">
                  <span className="text-muted-foreground">Invoice Total</span>
                  <span className="font-semibold">${Number(invoice.total_amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                </div>
                {planFormData.total_installments > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Estimated Per Installment</span>
                    <span className="font-semibold">
                      ${(Number(invoice.total_amount ?? 0) / planFormData.total_installments).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </span>
                  </div>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="plan_name">Plan Name (Optional)</Label>
                <Input
                  id="plan_name"
                  placeholder={`Installment Plan for ${invoice.invoice_number}`}
                  value={planFormData.plan_name}
                  onChange={(e) => setPlanFormData({ ...planFormData, plan_name: e.target.value })}
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="total_installments">Total Installments *</Label>
                  <Input
                    id="total_installments"
                    type="number"
                    min="2"
                    max="60"
                    value={planFormData.total_installments}
                    onChange={(e) => {
                      const value = parseInt(e.target.value) || 2;
                      if (value >= 2 && value <= 60) {
                        setPlanFormData({ ...planFormData, total_installments: value });
                      }
                    }}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="installment_amount">Installment Amount (Optional)</Label>
                  <Input
                    id="installment_amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="Auto-calculate"
                    value={planFormData.installment_amount}
                    onChange={(e) => setPlanFormData({ ...planFormData, installment_amount: e.target.value })}
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="frequency">Frequency *</Label>
                  <Select
                    value={planFormData.frequency}
                    onValueChange={(value: any) => setPlanFormData({ ...planFormData, frequency: value })}
                  >
                    <SelectTrigger id="frequency">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="daily">Daily</SelectItem>
                      <SelectItem value="weekly">Weekly</SelectItem>
                      <SelectItem value="biweekly">Bi-weekly</SelectItem>
                      <SelectItem value="monthly">Monthly</SelectItem>
                      <SelectItem value="quarterly">Quarterly</SelectItem>
                      <SelectItem value="custom">Custom</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="start_date">Start Date *</Label>
                  <Input
                    id="start_date"
                    type="date"
                    min={format(new Date(), 'yyyy-MM-dd')}
                    value={planFormData.start_date}
                    onChange={(e) => setPlanFormData({ ...planFormData, start_date: e.target.value })}
                  />
                </div>
              </div>

              {planFormData.frequency === 'custom' && (
                <div className="space-y-2">
                  <Label htmlFor="frequency_days">Days Between Installments *</Label>
                  <Input
                    id="frequency_days"
                    type="number"
                    min="1"
                    max="365"
                    value={planFormData.frequency_days}
                    onChange={(e) => {
                      const value = parseInt(e.target.value) || 30;
                      if (value >= 1 && value <= 365) {
                        setPlanFormData({ ...planFormData, frequency_days: value });
                      }
                    }}
                  />
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="notes">Notes (Optional)</Label>
                <Textarea
                  id="notes"
                  placeholder="Additional notes about this installment plan"
                  value={planFormData.notes}
                  onChange={(e) => setPlanFormData({ ...planFormData, notes: e.target.value })}
                  rows={3}
                />
              </div>

              <div className="flex items-center justify-between p-4 rounded-lg border">
                <div className="space-y-0.5">
                  <Label htmlFor="auto_charge" className="cursor-pointer">Auto Charge</Label>
                  <p className="text-sm text-muted-foreground">
                    Automatically charge the payment method when installments are due
                  </p>
                </div>
                <Switch
                  id="auto_charge"
                  checked={planFormData.auto_charge}
                  onCheckedChange={(checked) => setPlanFormData({ ...planFormData, auto_charge: checked })}
                />
              </div>

              <div className="flex justify-end gap-3 pt-4">
                <Button
                  variant="outline"
                  onClick={() => setIsCreatePlanDialogOpen(false)}
                  disabled={creatingPlan}
                >
                  Cancel
                </Button>
                <Button
                  onClick={handleCreateInstallmentPlan}
                  disabled={creatingPlan}
                >
                  {creatingPlan ? 'Creating...' : 'Create Plan'}
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* Enable Auto-Pay Dialog */}
      <Dialog open={isAutoPayDialogOpen} onOpenChange={setIsAutoPayDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Enable Auto-Pay</DialogTitle>
            <DialogDescription>
              Select a payment method to automatically pay this recurring invoice when it becomes due.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 mt-4">
            <div className="space-y-2">
              <Label>Payment Method</Label>
              <Select value={selectedPaymentMethod} onValueChange={setSelectedPaymentMethod}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a payment method" />
                </SelectTrigger>
                <SelectContent>
                  {paymentMethods
                    .filter((method) => method.is_active)
                    .map((method) => (
                      <SelectItem key={method.id} value={method.id.toString()}>
                        {method.name}
                        {method.last_four && ` •••• ${method.last_four}`}
                        {method.is_default && ' (Default)'}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              {paymentMethods.filter((m) => m.is_active).length === 0 && (
                <p className="text-xs text-muted-foreground">
                  No active payment methods available. Please add a payment method first.
                </p>
              )}
            </div>

            <div className="p-3 rounded-lg bg-muted/50 text-sm">
              <p className="text-muted-foreground">
                When auto-pay is enabled, this invoice will be automatically charged using the selected payment method on or before the due date.
              </p>
            </div>
          </div>

          <div className="flex justify-end gap-2 mt-6">
            <Button
              variant="outline"
              onClick={() => {
                setIsAutoPayDialogOpen(false);
                setSelectedPaymentMethod('');
              }}
            >
              Cancel
            </Button>
            <Button
              onClick={handleEnableAutoPay}
              disabled={!selectedPaymentMethod || togglingAutoPay}
            >
              {togglingAutoPay ? 'Enabling...' : 'Enable Auto-Pay'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
