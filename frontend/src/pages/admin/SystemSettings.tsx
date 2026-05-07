import { useState, useEffect } from 'react';
import { adminApi } from '@/lib/api/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { LoadingSpinner } from '@/components/LoadingSpinner';
import { toast } from 'sonner';
import { Save, Plus, Trash2, Settings as SettingsIcon } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';

interface SystemSetting {
  id: number;
  key: string;
  value: any;
  type: 'string' | 'number' | 'boolean' | 'json';
  category: string;
  label?: string;
  description?: string;
  is_public: boolean;
}

const CATEGORIES = [
  { value: 'general', label: 'General' },
  { value: 'payment', label: 'Payment' },
  { value: 'email', label: 'Email' },
  { value: 'invoice', label: 'Invoice' },
  { value: 'tax', label: 'Tax' },
  { value: 'security', label: 'Security' },
  { value: 'notification', label: 'Notification' },
];

export default function SystemSettings() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [settings, setSettings] = useState<Record<string, SystemSetting[]>>({});
  const [categories, setCategories] = useState<string[]>([]);
  const [selectedCategory, setSelectedCategory] = useState<string>('general');
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [newSetting, setNewSetting] = useState({
    key: '',
    value: '',
    type: 'string' as const,
    category: 'general',
    label: '',
    description: '',
    is_public: false,
  });

  useEffect(() => {
    loadSettings();
  }, []);

  const loadSettings = async () => {
    try {
      setLoading(true);
      const response = await adminApi.getSettings();
      if (response.data) {
        setSettings(response.data.settings || {});
        setCategories(response.data.categories || []);
      }
    } catch (error: any) {
      console.error('Failed to load settings:', error);
      toast.error(error?.message || 'Failed to load system settings');
    } finally {
      setLoading(false);
    }
  };

  const handleSaveBulk = async () => {
    try {
      setSaving(true);
      const settingsToUpdate = Object.values(settings)
        .flat()
        .map(setting => ({
          key: setting.key,
          value: setting.value,
        }));

      const response = await adminApi.updateSettingsBulk(settingsToUpdate);
      if (response.data) {
        toast.success('Settings saved successfully');
        await loadSettings();
      }
    } catch (error: any) {
      console.error('Failed to save settings:', error);
      toast.error(error?.message || 'Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  const handleSettingChange = (key: string, value: any) => {
    setSettings(prev => {
      const updated = { ...prev };
      Object.keys(updated).forEach(category => {
        updated[category] = updated[category].map(setting =>
          setting.key === key ? { ...setting, value } : setting
        );
      });
      return updated;
    });
  };

  const handleCreateSetting = async () => {
    try {
      if (!newSetting.key || !newSetting.key.match(/^[a-z0-9_]+$/)) {
        toast.error('Key must contain only lowercase letters, numbers, and underscores');
        return;
      }

      const response = await adminApi.createSetting(newSetting);
      if (response.data) {
        toast.success('Setting created successfully');
        setShowCreateDialog(false);
        setNewSetting({
          key: '',
          value: '',
          type: 'string',
          category: 'general',
          label: '',
          description: '',
          is_public: false,
        });
        await loadSettings();
      }
    } catch (error: any) {
      console.error('Failed to create setting:', error);
      toast.error(error?.message || 'Failed to create setting');
    }
  };

  const handleDeleteSetting = async (key: string) => {
    if (!confirm(`Are you sure you want to delete the setting "${key}"?`)) {
      return;
    }

    try {
      await adminApi.deleteSetting(key);
      toast.success('Setting deleted successfully');
      await loadSettings();
    } catch (error: any) {
      console.error('Failed to delete setting:', error);
      toast.error(error?.message || 'Failed to delete setting');
    }
  };

  const renderSettingInput = (setting: SystemSetting) => {
    const value = setting.value;

    switch (setting.type) {
      case 'boolean':
        return (
          <Switch
            checked={value === true || value === 'true' || value === '1'}
            onCheckedChange={(checked) => handleSettingChange(setting.key, checked)}
          />
        );
      case 'number':
        return (
          <Input
            type="number"
            value={value || ''}
            onChange={(e) => handleSettingChange(setting.key, parseFloat(e.target.value) || 0)}
          />
        );
      case 'json':
        return (
          <Textarea
            value={typeof value === 'string' ? value : JSON.stringify(value, null, 2)}
            onChange={(e) => {
              try {
                const parsed = JSON.parse(e.target.value);
                handleSettingChange(setting.key, parsed);
              } catch {
                handleSettingChange(setting.key, e.target.value);
              }
            }}
            rows={4}
          />
        );
      default:
        return (
          <Input
            type="text"
            value={value || ''}
            onChange={(e) => handleSettingChange(setting.key, e.target.value)}
          />
        );
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-6 animate-fade-in">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">System Settings</h1>
          <p className="text-muted-foreground mt-1">Manage system-wide configuration settings</p>
        </div>
        <div className="flex gap-2">
          <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="h-4 w-4 mr-2" />
                New Setting
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Create New Setting</DialogTitle>
                <DialogDescription>
                  Add a new system configuration setting
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4 py-4">
                <div className="space-y-2">
                  <Label>Key *</Label>
                  <Input
                    value={newSetting.key}
                    onChange={(e) => setNewSetting({ ...newSetting, key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') })}
                    placeholder="setting_key"
                  />
                  <p className="text-xs text-muted-foreground">Only lowercase letters, numbers, and underscores</p>
                </div>
                <div className="space-y-2">
                  <Label>Category *</Label>
                  <Select
                    value={newSetting.category}
                    onValueChange={(value) => setNewSetting({ ...newSetting, category: value })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CATEGORIES.map(cat => (
                        <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>Type *</Label>
                  <Select
                    value={newSetting.type}
                    onValueChange={(value: any) => setNewSetting({ ...newSetting, type: value })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="string">String</SelectItem>
                      <SelectItem value="number">Number</SelectItem>
                      <SelectItem value="boolean">Boolean</SelectItem>
                      <SelectItem value="json">JSON</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>Value</Label>
                  {newSetting.type === 'boolean' ? (
                    <Switch
                      checked={newSetting.value === true || newSetting.value === 'true'}
                      onCheckedChange={(checked) => setNewSetting({ ...newSetting, value: checked })}
                    />
                  ) : newSetting.type === 'json' ? (
                    <Textarea
                      value={typeof newSetting.value === 'string' ? newSetting.value : JSON.stringify(newSetting.value, null, 2)}
                      onChange={(e) => {
                        try {
                          const parsed = JSON.parse(e.target.value);
                          setNewSetting({ ...newSetting, value: parsed });
                        } catch {
                          setNewSetting({ ...newSetting, value: e.target.value });
                        }
                      }}
                      rows={4}
                    />
                  ) : (
                    <Input
                      type={newSetting.type === 'number' ? 'number' : 'text'}
                      value={newSetting.value || ''}
                      onChange={(e) => setNewSetting({ ...newSetting, value: e.target.value })}
                    />
                  )}
                </div>
                <div className="space-y-2">
                  <Label>Label</Label>
                  <Input
                    value={newSetting.label}
                    onChange={(e) => setNewSetting({ ...newSetting, label: e.target.value })}
                    placeholder="Display label"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Description</Label>
                  <Textarea
                    value={newSetting.description}
                    onChange={(e) => setNewSetting({ ...newSetting, description: e.target.value })}
                    placeholder="Setting description"
                    rows={3}
                  />
                </div>
                <div className="flex items-center space-x-2">
                  <Switch
                    id="is_public"
                    checked={newSetting.is_public}
                    onCheckedChange={(checked) => setNewSetting({ ...newSetting, is_public: checked })}
                  />
                  <Label htmlFor="is_public">Public (accessible without authentication)</Label>
                </div>
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setShowCreateDialog(false)}>Cancel</Button>
                <Button onClick={handleCreateSetting}>Create</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
          <Button onClick={handleSaveBulk} disabled={saving}>
            <Save className="h-4 w-4 mr-2" />
            {saving ? 'Saving...' : 'Save All Changes'}
          </Button>
        </div>
      </div>

      <Tabs value={selectedCategory} onValueChange={setSelectedCategory}>
        <TabsList>
          {CATEGORIES.map(cat => (
            <TabsTrigger key={cat.value} value={cat.value}>
              {cat.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {CATEGORIES.map(category => (
          <TabsContent key={category.value} value={category.value}>
            <Card>
              <CardHeader>
                <CardTitle>{category.label} Settings</CardTitle>
                <CardDescription>
                  Configure {category.label.toLowerCase()} related system settings
                </CardDescription>
              </CardHeader>
              <CardContent>
                {settings[category.value] && settings[category.value].length > 0 ? (
                  <div className="space-y-6">
                    {settings[category.value].map(setting => (
                      <div key={setting.id} className="space-y-2 border-b pb-4 last:border-0">
                        <div className="flex items-start justify-between">
                          <div className="flex-1">
                            <div className="flex items-center gap-2">
                              <Label className="text-base font-semibold">
                                {setting.label || setting.key}
                              </Label>
                              {setting.is_public && (
                                <span className="text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded">Public</span>
                              )}
                            </div>
                            {setting.description && (
                              <p className="text-sm text-muted-foreground mt-1">{setting.description}</p>
                            )}
                            <p className="text-xs text-muted-foreground mt-1 font-mono">{setting.key}</p>
                          </div>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => handleDeleteSetting(setting.key)}
                            className="text-destructive hover:text-destructive"
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                        <div className="mt-3">
                          {renderSettingInput(setting)}
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    <SettingsIcon className="h-12 w-12 mx-auto mb-2 opacity-50" />
                    <p>No settings found in this category</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        ))}
      </Tabs>
    </div>
  );
}

