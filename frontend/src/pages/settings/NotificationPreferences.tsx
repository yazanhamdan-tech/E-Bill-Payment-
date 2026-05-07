import { useState, useEffect } from 'react';
import { profileApi } from '@/lib/api/profile';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { LoadingSpinner } from '@/components/LoadingSpinner';
import { Switch } from '@/components/ui/switch';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { toast } from 'sonner';
import { Bell, Mail, Save, Database, FileText, CreditCard, AlertTriangle, CheckCircle } from 'lucide-react';
import { Link } from 'react-router-dom';
import {
  Alert,
  AlertDescription,
} from '@/components/ui/alert';

interface NotificationPreferences {
  email_notifications_enabled: boolean;
  database_notifications_enabled: boolean;
  email_reminders_enabled: boolean;
  reminder_7_days: boolean;
  reminder_3_days: boolean;
  reminder_1_day: boolean;
  reminder_overdue: boolean;
  notification_invoice_created_email: boolean;
  notification_invoice_created_database: boolean;
  notification_invoice_paid_email: boolean;
  notification_invoice_paid_database: boolean;
  notification_invoice_overdue_email: boolean;
  notification_invoice_overdue_database: boolean;
  notification_payment_completed_email: boolean;
  notification_payment_completed_database: boolean;
}

export default function NotificationPreferences() {
  const [loading, setLoading] = useState(false);
  const [fetching, setFetching] = useState(true);
  const [preferences, setPreferences] = useState<NotificationPreferences>({
    email_notifications_enabled: true,
    database_notifications_enabled: true,
    email_reminders_enabled: true,
    reminder_7_days: true,
    reminder_3_days: true,
    reminder_1_day: true,
    reminder_overdue: true,
    notification_invoice_created_email: true,
    notification_invoice_created_database: true,
    notification_invoice_paid_email: true,
    notification_invoice_paid_database: true,
    notification_invoice_overdue_email: true,
    notification_invoice_overdue_database: true,
    notification_payment_completed_email: true,
    notification_payment_completed_database: true,
  });

  useEffect(() => {
    loadPreferences();
  }, []);

  const loadPreferences = async () => {
    try {
      setFetching(true);
      const response = await profileApi.getPreferences();
      if (response.data?.preferences) {
        setPreferences({
          email_notifications_enabled: response.data.preferences.email_notifications_enabled ?? true,
          database_notifications_enabled: response.data.preferences.database_notifications_enabled ?? true,
          email_reminders_enabled: response.data.preferences.email_reminders_enabled ?? true,
          reminder_7_days: response.data.preferences.reminder_7_days ?? true,
          reminder_3_days: response.data.preferences.reminder_3_days ?? true,
          reminder_1_day: response.data.preferences.reminder_1_day ?? true,
          reminder_overdue: response.data.preferences.reminder_overdue ?? true,
          notification_invoice_created_email: response.data.preferences.notification_invoice_created_email ?? true,
          notification_invoice_created_database: response.data.preferences.notification_invoice_created_database ?? true,
          notification_invoice_paid_email: response.data.preferences.notification_invoice_paid_email ?? true,
          notification_invoice_paid_database: response.data.preferences.notification_invoice_paid_database ?? true,
          notification_invoice_overdue_email: response.data.preferences.notification_invoice_overdue_email ?? true,
          notification_invoice_overdue_database: response.data.preferences.notification_invoice_overdue_database ?? true,
          notification_payment_completed_email: response.data.preferences.notification_payment_completed_email ?? true,
          notification_payment_completed_database: response.data.preferences.notification_payment_completed_database ?? true,
        });
      }
    } catch (err: any) {
      console.error('Failed to fetch preferences:', err);
      toast.error('Failed to load notification preferences');
    } finally {
      setFetching(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
      const response = await profileApi.updatePreferences({
        preferences: preferences,
      });

      if (response.errors) {
        toast.error(response.message || 'Failed to update preferences');
      } else {
        toast.success('Notification preferences updated successfully');
      }
    } catch (err: any) {
      console.error('Preferences update error:', err);
      toast.error(err?.message || 'Failed to update preferences');
    } finally {
      setLoading(false);
    }
  };

  const togglePreference = (key: keyof NotificationPreferences) => {
    setPreferences((prev) => ({
      ...prev,
      [key]: !prev[key],
    }));
  };

  const toggleAllEmail = (enabled: boolean) => {
    setPreferences((prev) => ({
      ...prev,
      email_notifications_enabled: enabled,
      email_reminders_enabled: enabled,
      reminder_7_days: enabled,
      reminder_3_days: enabled,
      reminder_1_day: enabled,
      reminder_overdue: enabled,
      notification_invoice_created_email: enabled,
      notification_invoice_paid_email: enabled,
      notification_invoice_overdue_email: enabled,
      notification_payment_completed_email: enabled,
    }));
  };

  const toggleAllDatabase = (enabled: boolean) => {
    setPreferences((prev) => ({
      ...prev,
      database_notifications_enabled: enabled,
      notification_invoice_created_database: enabled,
      notification_invoice_paid_database: enabled,
      notification_invoice_overdue_database: enabled,
      notification_payment_completed_database: enabled,
    }));
  };

  if (fetching) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fade-in max-w-4xl">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Notification Preferences</h1>
          <p className="text-muted-foreground mt-1">Configure how and when you receive notifications</p>
        </div>
        <Link to="/settings/preferences">
          <Button variant="outline">Back to Preferences</Button>
        </Link>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Global Settings */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Bell className="h-5 w-5" />
              Global Notification Settings
            </CardTitle>
            <CardDescription>
              Control notification channels globally
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label className="text-base">Email Notifications</Label>
                <p className="text-sm text-muted-foreground">
                  Receive notifications via email
                </p>
              </div>
              <Switch
                checked={preferences.email_notifications_enabled}
                onCheckedChange={toggleAllEmail}
              />
            </div>
            <Separator />
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label className="text-base">In-App Notifications</Label>
                <p className="text-sm text-muted-foreground">
                  Receive notifications in the application
                </p>
              </div>
              <Switch
                checked={preferences.database_notifications_enabled}
                onCheckedChange={toggleAllDatabase}
              />
            </div>
          </CardContent>
        </Card>

        {/* Email Reminders */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Mail className="h-5 w-5" />
              Email Reminders
            </CardTitle>
            <CardDescription>
              Configure when you receive email reminders for upcoming invoices
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label className="text-base">Enable Email Reminders</Label>
                <p className="text-sm text-muted-foreground">
                  Master switch for all email reminders
                </p>
              </div>
              <Switch
                checked={preferences.email_reminders_enabled}
                onCheckedChange={(checked) => {
                  setPreferences((prev) => ({
                    ...prev,
                    email_reminders_enabled: checked,
                    reminder_7_days: checked ? prev.reminder_7_days : false,
                    reminder_3_days: checked ? prev.reminder_3_days : false,
                    reminder_1_day: checked ? prev.reminder_1_day : false,
                    reminder_overdue: checked ? prev.reminder_overdue : false,
                  }));
                }}
              />
            </div>

            {preferences.email_reminders_enabled && (
              <>
                <Separator />
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label className="text-base">7 Days Before Due Date</Label>
                      <p className="text-sm text-muted-foreground">
                        Receive a reminder 7 days before your invoice is due
                      </p>
                    </div>
                    <Switch
                      checked={preferences.reminder_7_days}
                      onCheckedChange={() => togglePreference('reminder_7_days')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                  <Separator />
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label className="text-base">3 Days Before Due Date</Label>
                      <p className="text-sm text-muted-foreground">
                        Receive a reminder 3 days before your invoice is due
                      </p>
                    </div>
                    <Switch
                      checked={preferences.reminder_3_days}
                      onCheckedChange={() => togglePreference('reminder_3_days')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                  <Separator />
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label className="text-base">1 Day Before Due Date</Label>
                      <p className="text-sm text-muted-foreground">
                        Receive a reminder 1 day before your invoice is due
                      </p>
                    </div>
                    <Switch
                      checked={preferences.reminder_1_day}
                      onCheckedChange={() => togglePreference('reminder_1_day')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                  <Separator />
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label className="text-base">Overdue Reminders</Label>
                      <p className="text-sm text-muted-foreground">
                        Receive reminders for invoices that are past their due date
                      </p>
                    </div>
                    <Switch
                      checked={preferences.reminder_overdue}
                      onCheckedChange={() => togglePreference('reminder_overdue')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                </div>
              </>
            )}
          </CardContent>
        </Card>

        {/* Invoice Notifications */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileText className="h-5 w-5" />
              Invoice Notifications
            </CardTitle>
            <CardDescription>
              Configure notifications for invoice-related events
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="text-base">Invoice Created</Label>
                  <p className="text-sm text-muted-foreground">
                    Notify me when a new invoice is created
                  </p>
                </div>
                <div className="flex items-center gap-4">
                  <div className="flex items-center gap-2">
                    <Mail className="h-4 w-4 text-muted-foreground" />
                    <Switch
                      checked={preferences.notification_invoice_created_email}
                      onCheckedChange={() => togglePreference('notification_invoice_created_email')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <Database className="h-4 w-4 text-muted-foreground" />
                    <Switch
                      checked={preferences.notification_invoice_created_database}
                      onCheckedChange={() => togglePreference('notification_invoice_created_database')}
                      disabled={!preferences.database_notifications_enabled}
                    />
                  </div>
                </div>
              </div>
              <Separator />
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="text-base">Invoice Paid</Label>
                  <p className="text-sm text-muted-foreground">
                    Notify me when an invoice is marked as paid
                  </p>
                </div>
                <div className="flex items-center gap-4">
                  <div className="flex items-center gap-2">
                    <Mail className="h-4 w-4 text-muted-foreground" />
                    <Switch
                      checked={preferences.notification_invoice_paid_email}
                      onCheckedChange={() => togglePreference('notification_invoice_paid_email')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <Database className="h-4 w-4 text-muted-foreground" />
                    <Switch
                      checked={preferences.notification_invoice_paid_database}
                      onCheckedChange={() => togglePreference('notification_invoice_paid_database')}
                      disabled={!preferences.database_notifications_enabled}
                    />
                  </div>
                </div>
              </div>
              <Separator />
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="text-base">Invoice Overdue</Label>
                  <p className="text-sm text-muted-foreground">
                    Notify me when an invoice becomes overdue
                  </p>
                </div>
                <div className="flex items-center gap-4">
                  <div className="flex items-center gap-2">
                    <Mail className="h-4 w-4 text-muted-foreground" />
                    <Switch
                      checked={preferences.notification_invoice_overdue_email}
                      onCheckedChange={() => togglePreference('notification_invoice_overdue_email')}
                      disabled={!preferences.email_notifications_enabled}
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <Database className="h-4 w-4 text-muted-foreground" />
                    <Switch
                      checked={preferences.notification_invoice_overdue_database}
                      onCheckedChange={() => togglePreference('notification_invoice_overdue_database')}
                      disabled={!preferences.database_notifications_enabled}
                    />
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Payment Notifications */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <CreditCard className="h-5 w-5" />
              Payment Notifications
            </CardTitle>
            <CardDescription>
              Configure notifications for payment-related events
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between">
              <div className="space-y-0.5">
                <Label className="text-base">Payment Completed</Label>
                <p className="text-sm text-muted-foreground">
                  Notify me when a payment is completed
                </p>
              </div>
              <div className="flex items-center gap-4">
                <div className="flex items-center gap-2">
                  <Mail className="h-4 w-4 text-muted-foreground" />
                  <Switch
                    checked={preferences.notification_payment_completed_email}
                    onCheckedChange={() => togglePreference('notification_payment_completed_email')}
                    disabled={!preferences.email_notifications_enabled}
                  />
                </div>
                <div className="flex items-center gap-2">
                  <Database className="h-4 w-4 text-muted-foreground" />
                  <Switch
                    checked={preferences.notification_payment_completed_database}
                    onCheckedChange={() => togglePreference('notification_payment_completed_database')}
                    disabled={!preferences.database_notifications_enabled}
                  />
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <Button type="submit" disabled={loading} className="w-full sm:w-auto">
          {loading ? (
            <>
              <LoadingSpinner size="sm" className="mr-2" />
              Saving...
            </>
          ) : (
            <>
              <Save className="h-4 w-4 mr-2" />
              Save Notification Preferences
            </>
          )}
        </Button>
      </form>
    </div>
  );
}

