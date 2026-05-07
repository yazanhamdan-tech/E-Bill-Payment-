import { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { LoadingSpinner } from '@/components/LoadingSpinner';
import { format, formatDistanceToNow } from 'date-fns';
import { 
  ArrowLeft, CheckCircle2, Clock, XCircle, RefreshCw, 
  CreditCard, FileText, AlertCircle, Loader2
} from 'lucide-react';
import { toast } from 'sonner';
import { paymentsApi, type Payment } from '@/lib/api/payments';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

interface TimelineItem {
  status: string;
  label: string;
  description: string;
  timestamp: string;
  completed: boolean;
  current?: boolean;
  refund?: boolean;
}

export default function PaymentTracking() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [payment, setPayment] = useState<Payment | null>(null);
  const [timeline, setTimeline] = useState<TimelineItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(false);
  const [estimatedCompletion, setEstimatedCompletion] = useState<string | null>(null);

  const loadTracking = async () => {
    if (!id) {
      setError('Payment ID not found');
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      setError(null);
      const response = await paymentsApi.track(Number(id));
      
      if (response.data) {
        setPayment(response.data.payment);
        setTimeline(response.data.timeline || []);
        setEstimatedCompletion(response.data.estimated_completion || null);
        
        // Auto-refresh if payment is pending or processing
        if (response.data.payment.status === 'pending' || response.data.payment.status === 'processing') {
          setAutoRefresh(true);
        } else {
          setAutoRefresh(false);
        }
      } else {
        setError(response.message || 'Payment not found');
      }
    } catch (err: any) {
      console.error('Error loading payment tracking:', err);
      const errorMessage = err?.message || 'Failed to load payment tracking';
      setError(errorMessage);
      toast.error(errorMessage);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadTracking();
  }, [id]);

  // Auto-refresh for pending/processing payments
  useEffect(() => {
    if (!autoRefresh) return;

    const interval = setInterval(() => {
      loadTracking();
    }, 5000); // Refresh every 5 seconds

    return () => clearInterval(interval);
  }, [autoRefresh, id]);

  const getStatusIcon = (status: string, completed: boolean, current?: boolean) => {
    if (current && !completed) {
      return <Loader2 className="h-5 w-5 text-primary animate-spin" />;
    }
    
    if (completed) {
      return <CheckCircle2 className="h-5 w-5 text-success" />;
    }
    
    return <Clock className="h-5 w-5 text-muted-foreground" />;
  };

  const getStatusColor = (status: string, completed: boolean, current?: boolean) => {
    if (current && !completed) {
      return 'border-primary';
    }
    
    if (completed) {
      if (status === 'failed') {
        return 'border-error';
      }
      if (status === 'refund' || status === 'refunded') {
        return 'border-info';
      }
      return 'border-success';
    }
    
    return 'border-muted';
  };

  if (loading && !payment) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner />
      </div>
    );
  }

  if (error || !payment) {
    return (
      <div className="flex flex-col items-center justify-center py-12">
        <h2 className="text-xl font-semibold mb-2">Payment not found</h2>
        <p className="text-muted-foreground mb-4">
          {error || "The payment you're looking for doesn't exist."}
        </p>
        <Button onClick={() => navigate('/payments')}>Go back to payments</Button>
      </div>
    );
  }

  const isPendingOrProcessing = payment.status === 'pending' || payment.status === 'processing';

  return (
    <div className="space-y-6 animate-fade-in max-w-4xl">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold">Payment Tracking</h1>
              <StatusBadge status={payment.status} size="lg" />
            </div>
            <p className="text-muted-foreground mt-1">{payment.payment_reference}</p>
          </div>
        </div>
        <div className="flex gap-3">
          {isPendingOrProcessing && (
            <Button 
              variant="outline" 
              onClick={loadTracking}
              disabled={loading}
            >
              <RefreshCw className={`h-4 w-4 mr-2 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
          )}
          <Button variant="outline" asChild>
            <Link to={`/payments/${id}`}>
              <FileText className="h-4 w-4 mr-2" />
              View Details
            </Link>
          </Button>
        </div>
      </div>

      {/* Payment Summary */}
      <Card>
        <CardHeader>
          <CardTitle>Payment Information</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <p className="text-sm text-muted-foreground mb-1">Amount</p>
              <p className="text-2xl font-bold text-success">
                ${parseFloat(payment.amount.toString()).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
              </p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Payment Method</p>
              <p className="text-lg font-semibold">
                {payment.payment_method?.type 
                  ? payment.payment_method.type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
                  : 'N/A'}
              </p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Created</p>
              <p className="text-lg font-semibold">
                {format(new Date(payment.created_at), 'MMM d, yyyy HH:mm')}
              </p>
            </div>
          </div>
          {payment.invoice && (
            <div className="mt-4 pt-4 border-t">
              <p className="text-sm text-muted-foreground mb-2">Related Invoice</p>
              <Link 
                to={`/invoices/${payment.invoice.id}`}
                className="text-primary hover:underline font-medium"
              >
                {payment.invoice.invoice_number} - {payment.invoice.title}
              </Link>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Status Timeline */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Payment Status Timeline</CardTitle>
              <CardDescription>
                Track the progress of your payment
              </CardDescription>
            </div>
            {isPendingOrProcessing && autoRefresh && (
              <Badge variant="outline" className="flex items-center gap-2">
                <Loader2 className="h-3 w-3 animate-spin" />
                Auto-refreshing
              </Badge>
            )}
          </div>
        </CardHeader>
        <CardContent>
          {timeline.length === 0 ? (
            <p className="text-sm text-muted-foreground text-center py-8">
              No tracking information available
            </p>
          ) : (
            <div className="space-y-6">
              {timeline.map((item, index) => {
                const isLast = index === timeline.length - 1;
                const itemDate = new Date(item.timestamp);
                
                return (
                  <div key={index} className="relative flex gap-4">
                    {/* Timeline line */}
                    {!isLast && (
                      <div className="absolute left-[10px] top-[24px] bottom-[-24px] w-0.5 bg-border" />
                    )}
                    
                    {/* Icon */}
                    <div className={`
                      relative z-10 flex h-10 w-10 items-center justify-center rounded-full border-2 bg-background
                      ${getStatusColor(item.status, item.completed, item.current)}
                    `}>
                      {getStatusIcon(item.status, item.completed, item.current)}
                    </div>
                    
                    {/* Content */}
                    <div className="flex-1 pb-6">
                      <div className="flex items-start justify-between gap-4">
                        <div className="flex-1">
                          <div className="flex items-center gap-2 mb-1">
                            <h4 className="font-semibold">{item.label}</h4>
                            {item.current && (
                              <Badge variant="outline" className="text-xs">
                                Current
                              </Badge>
                            )}
                            {item.refund && (
                              <Badge variant="secondary" className="text-xs">
                                Refund
                              </Badge>
                            )}
                          </div>
                          <p className="text-sm text-muted-foreground mb-2">
                            {item.description}
                          </p>
                          <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Clock className="h-3 w-3" />
                            <span>
                              {format(itemDate, 'MMM d, yyyy HH:mm')}
                              {item.completed && (
                                <span className="ml-2">
                                  ({formatDistanceToNow(itemDate, { addSuffix: true })})
                                </span>
                              )}
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Estimated Completion */}
      {estimatedCompletion && isPendingOrProcessing && (
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center gap-3">
              <AlertCircle className="h-5 w-5 text-warning" />
              <div>
                <p className="font-semibold">Estimated Completion</p>
                <p className="text-sm text-muted-foreground">
                  Payment is expected to be processed by {format(new Date(estimatedCompletion), 'MMM d, yyyy HH:mm')}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Gateway Information */}
      {payment.gateway_transaction_id && (
        <Card>
          <CardHeader>
            <CardTitle>Gateway Information</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Gateway:</span>
                <span className="text-sm font-medium">{payment.gateway || 'N/A'}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Transaction ID:</span>
                <span className="text-sm font-medium font-mono">{payment.gateway_transaction_id}</span>
              </div>
              {payment.processed_at && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Processed At:</span>
                  <span className="text-sm font-medium">
                    {format(new Date(payment.processed_at), 'MMM d, yyyy HH:mm')}
                  </span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

